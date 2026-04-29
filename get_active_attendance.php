<?php
session_start();
header("Content-Type: application/json");

require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();


try {
    $db = new Database();
    $conn = $db->connect();
    
    // Get today's date
    $today = date('Y-m-d');
    
    // Count total employees
    $stmt = $conn->prepare("SELECT COUNT(*) as total_employees FROM employees WHERE status = 'active'");
    $stmt->execute();
    $totalEmployees = $stmt->fetch(PDO::FETCH_ASSOC)['total_employees'];
    
    // Count employees who checked in today
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT employee_code) as active_today 
        FROM attendance 
        WHERE DATE(check_in_time) = ? AND check_out_time IS NULL
    ");
    $stmt->execute([$today]);
    $activeToday = $stmt->fetch(PDO::FETCH_ASSOC)['active_today'];
    
    // Calculate percentage
    $percentage = $totalEmployees > 0 ? round(($activeToday / $totalEmployees) * 100) : 0;
    
    $response = [
        'success' => true,
        'active_today' => $activeToday,
        'total_employees' => $totalEmployees,
        'percentage' => $percentage,
        'date' => $today
    ];
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $response = [
        'success' => false,
        'message' => 'Database error occurred',
        'active_today' => 0,
        'total_employees' => 0,
        'percentage' => 0
    ];
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $response = [
        'success' => false,
        'message' => 'An error occurred',
        'active_today' => 0,
        'total_employees' => 0,
        'percentage' => 0
    ];
}

echo json_encode($response);
?> 