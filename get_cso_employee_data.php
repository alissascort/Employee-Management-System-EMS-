<?php
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Check if user is logged in as CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    // CSO-specific data - overview of employee activities
    $data = [
        'total_employees' => 0,
        'active_employees' => 0,
        'employees_on_leave' => 0,
        'security_incidents' => 0,
        'pending_approvals' => 0
    ];
    
    // Get total employees count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM employees WHERE status = 'active'");
    $stmt->execute();
    $data['total_employees'] = (int)$stmt->fetchColumn();
    
    // Get active employees (checked in today)
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT employee_code) FROM attendance WHERE date = CURDATE() AND check_in_time IS NOT NULL");
    $stmt->execute();
    $data['active_employees'] = (int)$stmt->fetchColumn();
    
    // Get employees on leave today
    $stmt = $conn->prepare("SELECT COUNT(*) FROM leave_requests WHERE CURDATE() BETWEEN start_date AND end_date AND status = 'APPROVED'");
    $stmt->execute();
    $data['employees_on_leave'] = (int)$stmt->fetchColumn();
    
    // Get security incidents count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM security_incidents WHERE DATE(reported_at) = CURDATE()");
    $stmt->execute();
    $data['security_incidents'] = (int)$stmt->fetchColumn();
    
    // Get pending approvals (password recovery, etc.)
    $stmt = $conn->prepare("SELECT COUNT(*) FROM password_recovery_requests WHERE status = 'PENDING'");
    $stmt->execute();
    $data['pending_approvals'] = (int)$stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'data' => $data
    ]);
    
} catch (PDOException $e) {
    error_log("CSO Employee Data DB error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("CSO Employee Data error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred']);
}
?> 