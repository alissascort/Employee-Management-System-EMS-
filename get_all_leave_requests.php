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
    
    // Get all leave requests with employee details
    $stmt = $conn->prepare("
        SELECT 
            lr.leave_id,
            lr.employee_id,
            lr.employee_name,
            lr.leave_type,
            lr.start_date,
            lr.end_date,
            lr.reason,
            lr.status,
            lr.created_at,
            lr.email,
            lr.department,
            DATEDIFF(lr.end_date, lr.start_date) + 1 as days
        FROM leave_requests lr
        ORDER BY lr.created_at DESC
    ");
    
    $stmt->execute();
    $leave_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the data for display
    $formatted_requests = [];
    foreach ($leave_requests as $request) {
        // Split employee name into first and last name
        $name_parts = explode(' ', $request['employee_name'], 2);
        $firstname = $name_parts[0] ?? '';
        $lastname = $name_parts[1] ?? '';
        
        $formatted_requests[] = [
            'leave_id' => $request['leave_id'],
            'employee_id' => $request['employee_id'],
            'firstname' => $firstname,
            'lastname' => $lastname,
            'employee_name' => $request['employee_name'],
            'leave_type' => ucfirst($request['leave_type']),
            'start_date' => date('M d, Y', strtotime($request['start_date'])),
            'end_date' => date('M d, Y', strtotime($request['end_date'])),
            'reason' => $request['reason'],
            'status' => ucfirst($request['status']),
            'created_at' => $request['created_at'],
            'email' => $request['email'],
            'department' => $request['department'],
            'days' => $request['days']
        ];
    }
    
    $response = [
        'success' => true,
        'leave_requests' => $formatted_requests
    ];
    
} catch (PDOException $e) {
    error_log("Database Error in get_all_leave_requests.php: " . $e->getMessage());
    $response['message'] = 'Database error occurred';
} catch (Exception $e) {
    error_log("Error in get_all_leave_requests.php: " . $e->getMessage());
    $response['message'] = 'An error occurred';
}

echo json_encode($response);
?>
