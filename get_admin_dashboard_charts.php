<?php
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();
header("Content-Type: application/json");

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';

$response = ['success' => false, 'message' => 'An error occurred'];

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Get employee count by department
    $stmt = $conn->prepare("
        SELECT department, COUNT(*) as count 
        FROM employees 
        WHERE status = 'active' 
        GROUP BY department 
        ORDER BY count DESC
    ");
    $stmt->execute();
    $departmentData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get leave requests by status
    $stmt = $conn->prepare("
        SELECT status, COUNT(*) as count 
        FROM leave_requests 
        GROUP BY status
    ");
    $stmt->execute();
    $leaveData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attendance data for current month
    $currentMonth = date('Y-m');
    $stmt = $conn->prepare("
        SELECT 
            DATE(check_in_date) as date,
            COUNT(*) as present_count
        FROM attendance_records 
        WHERE DATE_FORMAT(check_in_date, '%Y-%m') = ?
        GROUP BY DATE(check_in_date)
        ORDER BY date
    ");
    $stmt->execute([$currentMonth]);
    $attendanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no real data, provide sample data
    if (empty($departmentData)) {
        $departmentData = [
            ['department' => 'IT', 'count' => 5],
            ['department' => 'HR', 'count' => 3],
            ['department' => 'Finance', 'count' => 4],
            ['department' => 'Sales', 'count' => 6],
            ['department' => 'Marketing', 'count' => 2]
        ];
    }
    
    if (empty($leaveData)) {
        $leaveData = [
            ['status' => 'PENDING', 'count' => 3],
            ['status' => 'APPROVED', 'count' => 8],
            ['status' => 'REJECTED', 'count' => 2]
        ];
    }
    
    if (empty($attendanceData)) {
        $attendanceData = [
            ['date' => '2025-07-01', 'present_count' => 15],
            ['date' => '2025-07-02', 'present_count' => 16],
            ['date' => '2025-07-03', 'present_count' => 14],
            ['date' => '2025-07-04', 'present_count' => 17],
            ['date' => '2025-07-05', 'present_count' => 15]
        ];
    }
    
    $response = [
        'success' => true,
        'departmentChart' => [
            'labels' => array_column($departmentData, 'department'),
            'data' => array_column($departmentData, 'count')
        ],
        'leaveChart' => [
            'labels' => array_column($leaveData, 'status'),
            'data' => array_column($leaveData, 'count')
        ],
        'attendanceChart' => [
            'labels' => array_column($attendanceData, 'date'),
            'data' => array_column($attendanceData, 'present_count')
        ]
    ];
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $response['message'] = 'Database error occurred';
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $response['message'] = 'An error occurred';
}

echo json_encode($response);
?> 