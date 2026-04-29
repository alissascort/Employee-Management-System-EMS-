<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing request ID']);
    exit;
}

$id = intval($_GET['id']);
$db = new Database();
$conn = $db->connect();
$stmt = $conn->prepare("SELECT * FROM leave_requests WHERE leave_id = ?");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if ($request) {
    echo json_encode(['success' => true, 'request' => $request]);
} else {
    echo json_encode(['success' => false, 'message' => 'Request not found']);
}
?>
