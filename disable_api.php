<?php
/**
 * disable_api.php
 * Sets is_active = 0 for a given API endpoint.
 * CSO-only.
 */
require_once __DIR__ . '/api_monitoring1.php';

header('Content-Type: application/json');

$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

if (session_status() === PHP_SESSION_NONE) session_start();

if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['user_type'] ?? '', ['cso', 'admin'], true)
) {
    http_response_code(401);
    $out = json_encode(['success' => false, 'message' => 'Unauthorized – CSO access required']);
    echo $out;
    $monitor->logRequest(401, null, $out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $out = json_encode(['success' => false, 'message' => 'POST required']);
    echo $out;
    $monitor->logRequest(405, null, $out);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];
$id   = isset($data['id']) ? (int)$data['id'] : 0;

if ($id <= 0) {
    http_response_code(422);
    $out = json_encode(['success' => false, 'message' => 'Valid API id required']);
    echo $out;
    $monitor->logRequest(422, $raw, $out);
    exit;
}

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=employee_management_system;charset=utf8mb4',
        'ems_user', 'securepassword123',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $db->prepare('UPDATE api_endpoints SET is_active = 0 WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        $out = json_encode(['success' => false, 'message' => 'API not found or already disabled']);
        http_response_code(404);
        echo $out;
        $monitor->logRequest(404, $raw, $out);
        exit;
    }

    $out = json_encode([
        'success'    => true,
        'message'    => 'API disabled successfully',
        'api_id'     => $id,
        'disabled_at'=> date('Y-m-d H:i:s'),
    ]);
    echo $out;
    $monitor->logRequest(200, $raw, $out);

} catch (PDOException $e) {
    error_log('disable_api error: ' . $e->getMessage());
    http_response_code(500);
    $out = json_encode(['success' => false, 'message' => 'Database error']);
    echo $out;
    $monitor->logRequest(500, $raw, $out);
}
