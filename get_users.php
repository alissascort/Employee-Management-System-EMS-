<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Only admin and department managers can access user management
if ($user_type !== 'admin' && $user_type !== 'dept_manager') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->connect();
    
    $users = [];
    
    // Get filter parameters
    $user_type_filter = $_GET['user_type'] ?? '';
    $department_filter = $_GET['department'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    
    if ($user_type === 'admin') {
        // Admin can see all users
        $sql = "SELECT 
                    'employee' as user_type,
                    employee_id as id,
                    first_name,
                    last_name,
                    email,
                    employee_code as identifier,
                    department,
                    position,
                    phone,
                    status,
                    hire_date as created_at
                FROM employees
                WHERE 1=1";
        
        $params = [];
        
        if ($user_type_filter && $user_type_filter === 'employee') {
            $sql .= " AND 1=1"; // Already filtering employees
        } elseif ($user_type_filter && $user_type_filter !== 'employee') {
            // For non-employee types, we'll handle separately
            $sql .= " AND 1=0"; // Don't show employees
        }
        
        if ($department_filter) {
            $sql .= " AND department = ?";
            $params[] = $department_filter;
        }
        
        if ($status_filter) {
            $sql .= " AND status = ?";
            $params[] = $status_filter;
        }
        
        if ($search) {
            $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR employee_code LIKE ?)";
            $searchParam = "%$search%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add admin users if requested
        if (!$user_type_filter || $user_type_filter === 'admin') {
            $adminSql = "SELECT 
                            'admin' as user_type,
                            admin_id as id,
                            full_name as first_name,
                            '' as last_name,
                            email,
                            email as identifier,
                            'Administration' as department,
                            'Administrator' as position,
                            '' as phone,
                            'active' as status,
                            NOW() as created_at
                        FROM admins
                        WHERE 1=1";
            
            $adminParams = [];
            
            if ($search) {
                $adminSql .= " AND (full_name LIKE ? OR email LIKE ?)";
                $searchParam = "%$search%";
                $adminParams[] = $searchParam;
                $adminParams[] = $searchParam;
            }
            
            $adminSql .= " ORDER BY created_at DESC";
            
            $stmt = $pdo->prepare($adminSql);
            $stmt->execute($adminParams);
            $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $users = array_merge($employees, $admins);
        } else {
            $users = $employees;
        }
        
        // Add other user types if requested
        if ($user_type_filter === 'dept_manager' || $user_type_filter === 'hr' || $user_type_filter === 'cso') {
            // Handle different user types with their respective tables
            switch ($user_type_filter) {
                case 'dept_manager':
                    $otherSql = "SELECT 
                                    'dept_manager' as user_type,
                                    manager_id as id,
                                    full_name as first_name,
                                    '' as last_name,
                                    email,
                                    email as identifier,
                                    department,
                                    'Manager' as position,
                                    '' as phone,
                                    'active' as status,
                                    NOW() as created_at
                                FROM dept_managers
                                WHERE 1=1";
                    break;
                    
                case 'hr':
                    $otherSql = "SELECT 
                                    'hr' as user_type,
                                    hr_id as id,
                                    full_name as first_name,
                                    '' as last_name,
                                    email,
                                    email as identifier,
                                    'HR' as department,
                                    'HR Staff' as position,
                                    '' as phone,
                                    'active' as status,
                                    NOW() as created_at
                                FROM hr
                                WHERE 1=1";
                    break;
                    
                case 'cso':
                    $otherSql = "SELECT 
                                    'cso' as user_type,
                                    cso_id as id,
                                    full_name as first_name,
                                    '' as last_name,
                                    email,
                                    email as identifier,
                                    'Security' as department,
                                    'CSO Staff' as position,
                                    '' as phone,
                                    'active' as status,
                                    created_at
                                FROM csos
                                WHERE 1=1";
                    break;
            }
            
            $otherParams = [];
            
            if ($department_filter && $user_type_filter === 'dept_manager') {
                $otherSql .= " AND department = ?";
                $otherParams[] = $department_filter;
            }
            
            if ($search) {
                $otherSql .= " AND (full_name LIKE ? OR email LIKE ?)";
                $searchParam = "%$search%";
                $otherParams[] = $searchParam;
                $otherParams[] = $searchParam;
            }
            
            $otherSql .= " ORDER BY created_at DESC";
            
            $stmt = $pdo->prepare($otherSql);
            $stmt->execute($otherParams);
            $others = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $users = array_merge($users, $others);
        }
        
    } elseif ($user_type === 'dept_manager') {
        // Department manager can only see users from their department
        $stmt = $pdo->prepare("SELECT department FROM dept_managers WHERE manager_id = ?");
        $stmt->execute([$user_id]);
        $manager = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($manager && $manager['department']) {
            $sql = "SELECT 
                        'employee' as user_type,
                        employee_id as id,
                        first_name,
                        last_name,
                        email,
                        employee_code as identifier,
                        department,
                        position,
                        phone,
                        status,
                        hire_date as created_at
                    FROM employees
                    WHERE department = ?";
            
            $params = [$manager['department']];
            
            if ($status_filter) {
                $sql .= " AND status = ?";
                $params[] = $status_filter;
            }
            
            if ($search) {
                $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR employee_code LIKE ?)";
                $searchParam = "%$search%";
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            
            $sql .= " ORDER BY created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // Format the users data
    $formattedUsers = [];
    foreach ($users as $user) {
        $formattedUsers[] = [
            'id' => $user['id'],
            'name' => $user['first_name'] . ' ' . $user['last_name'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'identifier' => $user['identifier'],
            'user_type' => $user['user_type'],
            'department' => $user['department'],
            'position' => $user['position'],
            'phone' => $user['phone'],
            'status' => $user['status'],
            'created_at' => $user['created_at']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'users' => $formattedUsers,
        'total_count' => count($formattedUsers),
        'filters' => [
            'user_type' => $user_type_filter,
            'department' => $department_filter,
            'status' => $status_filter,
            'search' => $search
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?> 