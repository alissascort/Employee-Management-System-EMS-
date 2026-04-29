<?php
// get_announcements.php
session_start();
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();


$database = new Database();
$db = $database->getConnection();

// Get user context - with better defaults for HR users
$userId = isset($_GET['user_id']) && $_GET['user_id'] !== 'null' ? intval($_GET['user_id']) : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1);
$userRole = isset($_GET['role']) && $_GET['role'] !== 'null' ? $_GET['role'] : (isset($_SESSION['user_type']) ? $_SESSION['user_type'] : 'hr');
$userDept = isset($_GET['department_id']) && $_GET['department_id'] !== 'null' ? intval($_GET['department_id']) : (isset($_SESSION['department_id']) ? $_SESSION['department_id'] : 1);

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$isHrView = isset($_GET['hr_view']) ? $_GET['hr_view'] === 'true' : false;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'active';

try {
    if ($isHrView || $userRole === 'hr') {
        // HR view - show ALL announcements for management with optional status filter
        $query = "SELECT a.* FROM announcements a WHERE 1=1";
        
        if ($statusFilter && $statusFilter !== '') {
            $query .= " AND a.status = :status";
        }
        
        $query .= " ORDER BY a.date_posted DESC LIMIT :limit";
        
        $stmt = $db->prepare($query);
        
        if ($statusFilter && $statusFilter !== '') {
            $stmt->bindValue(':status', $statusFilter);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    } else {
        // User-specific view with intelligent filtering
        $query = "
            SELECT a.*
            FROM announcements a
            WHERE a.status = 'active'
              AND (
                    -- Show all announcements marked for 'all' audience
                    a.audience = 'all'
                    
                    -- Show role-based announcements if user role matches
                    OR (a.audience = 'role' AND (
                        a.target_role = :user_role 
                        OR a.target_role = 'employee'  -- Most employees should see 'employee' role announcements
                        OR :user_role = 'hr'  -- HR should see all role-based announcements
                    ))
                    
                    -- Show department-based announcements if user department matches
                    OR (a.audience = 'department' AND (
                        a.target_department_id = :user_dept 
                        OR :user_role = 'hr'  -- HR should see all department announcements
                    ))
                    
                    -- Show specific announcements (simplified - show all for now)
                    OR (a.audience = 'specific' AND :user_role = 'hr')
                  )
            ORDER BY a.date_posted DESC
            LIMIT :limit
        ";

        $stmt = $db->prepare($query);
        $stmt->bindValue(':user_role', $userRole);
        $stmt->bindValue(':user_dept', $userDept, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }

    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Return proper JSON structure expected by frontend
    echo json_encode([
        'success' => true,
        'data' => $announcements,
        'total_count' => count($announcements),
        'filters_applied' => [
            'user_role' => $userRole,
            'user_dept' => $userDept,
            'hr_view' => $isHrView,
            'status_filter' => $statusFilter
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load announcements',
        'error' => $e->getMessage()
    ]);
}
?>