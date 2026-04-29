<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

$data = json_decode(file_get_contents('php://input'), true);
$db = new Database();
$conn = $db->connect();
$stmt = $conn->prepare("UPDATE password_recovery_requests SET status = ?, admin_notes = ? WHERE id = ?");
$stmt->execute([$data['action'] === 'approve' ? 'APPROVED' : 'DENIED', $data['notes'], $data['id']]);
echo json_encode(['success' => true]);
?>
 