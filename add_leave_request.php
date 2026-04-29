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
    
    // Get form data
    $employee_id = intval($_POST['employeeId']);
    $leave_type = trim($_POST['leaveType']);
    $start_date = $_POST['startDate'];
    $end_date = $_POST['endDate'];
    $reason = trim($_POST['leaveReason']);
    
    // Validate required fields
    if (!$employee_id || !$leave_type || !$start_date || !$end_date || !$reason) {
        $response['message'] = 'All fields are required';
        echo json_encode($response);
        exit;
    }
    
    // Validate dates
    if (strtotime($start_date) > strtotime($end_date)) {
        $response['message'] = 'Start date cannot be after end date';
        echo json_encode($response);
        exit;
    }
    
    if (strtotime($start_date) < strtotime(date('Y-m-d'))) {
        $response['message'] = 'Start date cannot be in the past';
        echo json_encode($response);
        exit;
    }
    
    // Get employee details
    $stmt = $conn->prepare("SELECT first_name, last_name, email, department FROM employees WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        $response['message'] = 'Employee not found';
        echo json_encode($response);
        exit;
    }
    
    // Calculate duration
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $duration = $start->diff($end)->days + 1;
    
    // Insert leave request
    $stmt = $conn->prepare("
        INSERT INTO leave_requests (
            employee_id, employee_name, leave_type, start_date, end_date, 
            reason, status, created_at, email, department
        ) VALUES (
            ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?, ?
        )
    ");
    
    $employee_name = $employee['first_name'] . ' ' . $employee['last_name'];
    
    $stmt->execute([
        $employee_id,
        $employee_name,
        $leave_type,
        $start_date,
        $end_date,
        $reason,
        $employee['email'],
        $employee['department']
    ]);
    
    if ($stmt->rowCount() > 0) {
        $response = [
            'success' => true,
            'message' => 'Leave request submitted successfully',
            'leave_id' => $conn->lastInsertId()
        ];
    } else {
        $response['message'] = 'Failed to submit leave request';
    }
    
} catch (PDOException $e) {
    error_log("Database Error in add_leave_request.php: " . $e->getMessage());
    $response['message'] = 'Database error occurred';
} catch (Exception $e) {
    error_log("Error in add_leave_request.php: " . $e->getMessage());
    $response['message'] = 'An error occurred';
}

echo json_encode($response);
?>

