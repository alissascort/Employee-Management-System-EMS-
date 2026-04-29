<?php
header("Content-Type: application/json");
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'employee') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$employee_id = $_SESSION['user_id'];

try {
    $db = new Database();
    $conn = $db->connect();
    $stmt = $conn->prepare("SELECT type, start_date, end_date, days, reason, status, approved_on FROM leave_requests WHERE employee_id = :employee_id AND status = 'Approved' ORDER BY approved_on DESC");
    $stmt->bindParam(':employee_id', $employee_id);
    $stmt->execute();
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'leaves' => $leaves]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
