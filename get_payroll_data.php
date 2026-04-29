<?php
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
    
    // Note: payroll_records table doesn't exist, using zero values
    $thisMonthTotal = 0;
    $thisYearTotal = 0;
    $employeesPaidThisMonth = 0;
    $pendingCount = 0;
    
    // Empty arrays for charts since no data exists
    $recentPayrolls = [];
    $payrollHistory = [];
    
    $response = [
        'success' => true,
        'stats' => [
            'this_month_total' => $thisMonthTotal,
            'this_year_total' => $thisYearTotal,
            'employees_paid_this_month' => $employeesPaidThisMonth,
            'pending_count' => $pendingCount
        ],
        'recent_payrolls' => $recentPayrolls,
        'payroll_history' => $payrollHistory
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

