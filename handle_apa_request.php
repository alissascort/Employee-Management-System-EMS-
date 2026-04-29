<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (empty($input['request_id']) || empty($input['action'])) {
        throw new Exception('Request ID and action are required');
    }

    if (!in_array($input['action'], ['approve', 'deny'])) {
        throw new Exception('Invalid action');
    }

    // Connect to database
    $db = (new Database())->connect();

    // Check if the request exists
    $stmt = $db->prepare("SELECT * FROM password_recovery_requests WHERE id = ?");
    $stmt->execute([$input['request_id']]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        throw new Exception('Request not found');
    }

    // Update the request status
    $status = $input['action'] === 'approve' ? 'APPROVED' : 'DENIED';
    $stmt = $db->prepare("UPDATE password_recovery_requests SET status = ?, admin_notes = ? WHERE id = ?");
    $success = $stmt->execute([$status, $input['notes'] ?? null, $input['request_id']]);

    if (!$success) {
        throw new Exception('Failed to update request status');
    }

    echo json_encode([
        'success' => true,
        'message' => "Request {$status} successfully"
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
