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
    
    // Get current month and year
    $currentMonth = date('Y-m');
    $currentYear = date('Y');
    
    // Check if payroll_records table exists
    $tableExists = false;
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE 'payroll_records'");
        $stmt->execute();
        $tableExists = $stmt->rowCount() > 0;
    } catch (Exception $e) {
        $tableExists = false;
    }
    
    if ($tableExists) {
        // Get payroll data from existing table
        $stmt = $conn->prepare("
            SELECT 
                SUM(CASE WHEN DATE_FORMAT(pay_period, '%Y-%m') = ? THEN net_salary ELSE 0 END) as this_month,
                SUM(CASE WHEN YEAR(pay_period) = ? THEN net_salary ELSE 0 END) as this_year,
                COUNT(DISTINCT CASE WHEN DATE_FORMAT(pay_period, '%Y-%m') = ? THEN employee_id END) as employees_paid,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending
            FROM payroll_records
        ");
        $stmt->execute([$currentMonth, $currentYear, $currentMonth]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $thisMonth = $result['this_month'] ?? 0;
        $thisYear = $result['this_year'] ?? 0;
        $employeesPaid = $result['employees_paid'] ?? 0;
        $pending = $result['pending'] ?? 0;
    } else {
        // Table doesn't exist, use zero values
        $thisMonth = 0;
        $thisYear = 0;
        $employeesPaid = 0;
        $pending = 0;
    }
    
    $response = [
        'success' => true,
        'this_month' => (int)$thisMonth,
        'this_year' => (int)$thisYear,
        'employees_paid' => (int)$employeesPaid,
        'pending' => (int)$pending
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