<?php
// get_LeaveRequests.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once 'db_connect.php';

$database = new Database();
$db = $database->getConnection();

$status = isset($_GET['status']) ? $_GET['status'] : null;

// Fixed: replaced e.id with e.employee_id
$query = "SELECT 
              lr.*, 
              e.first_name, 
              e.last_name, 
              e.department, 
              e.position,
              e.employee_code,
              DATEDIFF(lr.end_date, lr.start_date) + 1 AS days
          FROM leave_requests lr
          JOIN employees e ON lr.employee_id = e.employee_id";

if ($status) {
    $query .= " WHERE lr.status = :status";
}

$query .= " ORDER BY lr.created_at DESC";

$stmt = $db->prepare($query);

if ($status) {
    $stmt->bindParam(':status', $status);
}

$stmt->execute();

$leave_requests = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $leave_requests[] = $row;
}

http_response_code(200);
echo json_encode($leave_requests);
?>
