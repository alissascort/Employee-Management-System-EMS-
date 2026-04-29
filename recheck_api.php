<?php
/**
 * recheck_api.php
 * Performs a live HTTP check on a registered API and
 * updates api_endpoints + inserts into api_monitoring_history.
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
    $out = json_encode(['success' => false, 'message' => 'Unauthorized']);
    echo $out;
    $monitor->logRequest(401, null, $out);
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

    // Get the endpoint URL
    $stmt = $db->prepare('SELECT id, endpoint_url FROM api_endpoints WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $api = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$api) {
        http_response_code(404);
        $out = json_encode(['success' => false, 'message' => 'API not found']);
        echo $out;
        $monitor->logRequest(404, $raw, $out);
        exit;
    }

    // Build full URL (assumes same server; adjust base URL as needed)
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $url  = $base . $api['endpoint_url'];

    // Ping
    $result = ApiMonitor::pingEndpoint($url, 3000);

    // Update api_endpoints
    $db->prepare("
        UPDATE api_endpoints
        SET status = ?, response_time = ?, last_check = NOW()
        WHERE id = ?
    ")->execute([$result['status'], (int)$result['response_time'], $id]);

    // Insert history record
    $db->prepare("
        INSERT INTO api_monitoring_history (api_id, status, response_time, error_message, check_time)
        VALUES (?, ?, ?, ?, NOW())
    ")->execute([
        $id,
        $result['status'],
        $result['response_time'],
        $result['error'],
    ]);

    $out = json_encode([
        'success'       => true,
        'message'       => 'Recheck complete',
        'api_id'        => $id,
        'status'        => $result['status'],
        'response_time' => $result['response_time'],
        'error'         => $result['error'],
        'checked_at'    => date('Y-m-d H:i:s'),
    ]);
    echo $out;
    $monitor->logRequest(200, $raw, $out);

} catch (PDOException $e) {
    error_log('recheck_api error: ' . $e->getMessage());
    http_response_code(500);
    $out = json_encode(['success' => false, 'message' => 'Database error']);
    echo $out;
    $monitor->logRequest(500, $raw, $out);
}
