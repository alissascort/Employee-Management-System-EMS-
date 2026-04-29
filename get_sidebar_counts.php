<?php
require_once 'db_connect.php';

header('Content-Type: application/json');

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Get employee count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM employees WHERE status = 'active'");
    $stmt->execute();
    $employeeCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get department count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM departments WHERE status = 'active'");
    $stmt->execute();
    $departmentCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get pending leave requests count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM leave_requests WHERE status = 'pending'");
    $stmt->execute();
    $leaveRequestCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get incomplete employee records count
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM employees e 
        LEFT JOIN staff_profiles sp ON e.employee_id = sp.employee_id 
        WHERE e.status = 'active' 
        AND sp.id IS NULL
    ");
    $stmt->execute();
    $incompleteRecordsCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get pending payroll count
    $pendingPayrollCount = 0;
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE 'payroll_records'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM payroll_records WHERE status = 'pending'");
            $stmt->execute();
            $pendingPayrollCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }
    } catch (Exception $e) {
        $pendingPayrollCount = 0;
    }
    
    // Get today's attendance count
    $todayAttendanceCount = 0;
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE 'attendance_records'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $today = date('Y-m-d');
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM attendance_records WHERE DATE(check_in_time) = ?");
            $stmt->execute([$today]);
            $todayAttendanceCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }
    } catch (Exception $e) {
        $todayAttendanceCount = 0;
    }
    
    // Get open tickets count
    $openTicketsCount = 0;
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE 'tickets'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tickets WHERE status = 'open'");
            $stmt->execute();
            $openTicketsCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }
    } catch (Exception $e) {
        $openTicketsCount = 0;
    }
    
    echo json_encode([
        'success' => true,
        'counts' => [
            'employees' => $employeeCount,
            'departments' => $departmentCount,
            'leave_requests' => $leaveRequestCount,
            'incomplete_records' => $incompleteRecordsCount,
            'pending_payroll' => $pendingPayrollCount,
            'today_attendance' => $todayAttendanceCount,
            'open_tickets' => $openTicketsCount
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Error fetching sidebar counts: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch counts: ' . $e->getMessage(),
        'counts' => [
            'employees' => 0,
            'departments' => 0,
            'leave_requests' => 0,
            'incomplete_records' => 0,
            'pending_payroll' => 0,
            'today_attendance' => 0,
            'open_tickets' => 0
        ]
    ]);
}
?> 