<?php
header("Content-Type: application/json");
session_start();
require_once 'db_connect.php';

// Check session
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'employee') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$employee_id = $_SESSION['user_id'];

try {
    $db = new Database();
    $conn = $db->connect();

    // First get employee email to match the get_employee_data.php query method
    $stmt = $conn->prepare("SELECT email FROM employees WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit;
    }
    
    $employeeEmail = $employee['email'];

    // Now query using email to match get_employee_data.php
    $stmt = $conn->prepare("
        SELECT 
            leave_type,
            start_date,
            end_date,
            DATEDIFF(end_date, start_date) + 1 AS days,
            reason,
            status,
            created_at
        FROM leave_requests 
        WHERE email = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$employeeEmail]);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

     // Transform the data to ensure consistent field names
    $transformedLeaves = array_map(function($leave) {
        return [
            'type' => $leave['leave_type'],
            'leave_type' => $leave['leave_type'], // Keep both for compatibility
            'start_date' => $leave['start_date'],
            'end_date' => $leave['end_date'],
            'days' => $leave['days'],
            'reason' => $leave['reason'],
            'status' => $leave['status']
        ];
    }, $leaves);

    echo json_encode([
        'success' => true, 
        'leaves' => $transformedLeaves,
        'count' => count($leaves)
    ]);
    
} catch (PDOException $e) {
    error_log("Leave fetch error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>