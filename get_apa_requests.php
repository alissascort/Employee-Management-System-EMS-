<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
$db = new Database();
$conn = $db->connect();
$stmt = $conn->prepare("SELECT * FROM password_recovery_requests ORDER BY request_time DESC");
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'requests' => $requests]);
?>
