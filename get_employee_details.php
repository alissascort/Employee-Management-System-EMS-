<?php
session_start();
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Debug session
error_log('Session data: ' . print_r($_SESSION, true));
error_log('User ID: ' . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log('User type: ' . ($_SESSION['user_type'] ?? 'NOT SET'));

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    error_log('Unauthorized access - User ID: ' . ($_SESSION['user_id'] ?? 'NOT SET') . ', User type: ' . ($_SESSION['user_type'] ?? 'NOT SET'));
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
    exit;
}

$employeeId = intval($_GET['id']);

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Get employee details from staff_profiles table (since that's what the frontend is using)
    $stmt = $conn->prepare("
        SELECT sp.*, e.* 
        FROM staff_profiles sp
        LEFT JOIN employees e ON sp.employee_id = e.employee_id 
        WHERE sp.employee_id = :id
    ");
    $stmt->bindParam(':id', $employeeId);
    $stmt->execute();
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log('Employee query result: ' . ($employee ? 'Found' : 'Not found'));
    error_log('Employee ID searched: ' . $employeeId);
    
    if (!$employee) {
        error_log('Employee not found in database for ID: ' . $employeeId);
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit;
    }
    
    error_log('Employee found: ' . print_r($employee, true));
    
    echo json_encode([
        'success' => true,
        'employee' => $employee
    ]);
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?> 