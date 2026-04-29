<?php
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['employee', 'cso'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Check if user is CSO or Employee
    $isCSO = ($_SESSION['user_type'] === 'cso');
    
    if ($isCSO) {
        // CSO gets overview data for all employees
        $data = [
            'total_employees' => 0,
            'active_employees' => 0,
            'employees_on_leave' => 0,
            'pending_leave_requests' => 0,
            'approved_leave_requests' => 0,
            'rejected_leave_requests' => 0,
            'total_pending_tasks' => 0,
            'total_completed_tasks' => 0,
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
        
        // Get leave request counts
        $stmt = $conn->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'PENDING'");
        $stmt->execute();
        $data['pending_leave_requests'] = (int)$stmt->fetchColumn();
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'APPROVED'");
        $stmt->execute();
        $data['approved_leave_requests'] = (int)$stmt->fetchColumn();
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'REJECTED'");
        $stmt->execute();
        $data['rejected_leave_requests'] = (int)$stmt->fetchColumn();
        
        // Get task counts
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE status = 'PENDING'");
        $stmt->execute();
        $data['total_pending_tasks'] = (int)$stmt->fetchColumn();
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE status = 'COMPLETED'");
        $stmt->execute();
        $data['total_completed_tasks'] = (int)$stmt->fetchColumn();
        
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
            'user_type' => 'cso',
            'data' => $data
        ]);
        exit;
    } else {
        // Employee gets their personal data
        $employeeId = $_SESSION['user_id'];
        
        // Get employee email for queries
        $stmt = $conn->prepare("SELECT email FROM employees WHERE employee_id = ?");
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$employee) {
            echo json_encode(['success' => false, 'message' => 'Employee not found']);
            exit;
        }
        
        $employeeEmail = $employee['email'];
    
    // Initialize counts
    $counts = [
        'pending_leave' => 0,
        'approved_leave' => 0,
        'rejected_leave' => 0,
        'pending_tasks' => 0,
        'completed_tasks' => 0
    ];
    
    // Check if leave_requests table exists, if not create it
    $stmt = $conn->query("SHOW TABLES LIKE 'leave_requests'");
    if ($stmt->rowCount() == 0) {
        $conn->exec("CREATE TABLE IF NOT EXISTS leave_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            reason TEXT NOT NULL,
            status ENUM('PENDING', 'APPROVED', 'REJECTED') DEFAULT 'PENDING',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }
    
    // Check if tasks table exists, if not create it
    $stmt = $conn->query("SHOW TABLES LIKE 'tasks'");
    if ($stmt->rowCount() == 0) {
        $conn->exec("CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            assigned_to VARCHAR(255) NOT NULL,
            assigned_by VARCHAR(255) NOT NULL,
            due_date DATE NOT NULL,
            status ENUM('PENDING', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED') DEFAULT 'PENDING',
            priority ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') DEFAULT 'MEDIUM',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_date DATE NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }
    
    // Get pending leave requests count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM leave_requests WHERE email = ? AND status = 'PENDING'");
    $stmt->execute([$employeeEmail]);
    $counts['pending_leave'] = (int)$stmt->fetchColumn();
    
    // Get approved leave requests count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM leave_requests WHERE email = ? AND status = 'APPROVED'");
    $stmt->execute([$employeeEmail]);
    $counts['approved_leave'] = (int)$stmt->fetchColumn();
    
    // Get rejected leave requests count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM leave_requests WHERE email = ? AND status = 'REJECTED'");
    $stmt->execute([$employeeEmail]);
    $counts['rejected_leave'] = (int)$stmt->fetchColumn();
    
    // Get today's pending tasks count
    $currentDate = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND due_date = ? AND status = 'PENDING'");
    $stmt->execute([$_SESSION['employee_code'], $currentDate]);
    $counts['pending_tasks'] = (int)$stmt->fetchColumn();
    
    // Get completed tasks count for this week
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND completed_date >= ? AND status = 'COMPLETED'");
    $stmt->execute([$_SESSION['employee_code'], $weekStart]);
    $counts['completed_tasks'] = (int)$stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'user_type' => 'employee',
        'counts' => $counts,
        'employee_email' => $employeeEmail
    ]);
    }
    
} catch (PDOException $e) {
    error_log("Employee data DB error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Employee data error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred']);
}
?>
