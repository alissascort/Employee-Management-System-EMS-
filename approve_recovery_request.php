<?php
header('Content-Type: application/json');
// Load DB config securely
require_once __DIR__ . '/db_connect.php';
$db = (new Database())->connect();
if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$request_id = $data['request_id'] ?? '';
$action = $data['action'] ?? '';
$notes = $data['notes'] ?? '';

if (!$request_id || !in_array($action, ['approve', 'deny'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $status = $action === 'approve' ? 'APPROVED' : 'DENIED';

    // Update the request status and admin notes
    $stmt = $db->prepare("UPDATE password_recovery_requests SET status = ?, admin_notes = ? WHERE id = ? AND status = 'PENDING'");
    $stmt->execute([$status, $notes, $request_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No pending request found or already processed.']);
    }
} catch (PDOException $e) {
    error_log("Approval error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
