<?php
/**
 * api_monitoring.php
 * Single-API detail endpoint.
 *   GET  ?id=N  → returns API info + last 10 history records
 *   POST        → triggers live recheck and logs result
 *
 * Integrated with core ApiMonitor.
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

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=employee_management_system;charset=utf8mb4',
        'ems_user', 'securepassword123',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    /* ────────────────── GET – fetch API detail ──────────── */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            $out = json_encode(['success' => false, 'message' => 'id required']);
            http_response_code(422);
            echo $out;
            $monitor->logRequest(422, null, $out);
            exit;
        }

        $stmt = $db->prepare("
            SELECT ae.*,
                   COALESCE(lc.usage_count, 0) AS usage_count,
                   COALESCE(lc.error_count, 0) AS error_count
            FROM api_endpoints ae
            LEFT JOIN (
                SELECT endpoint,
                       COUNT(*)                                             AS usage_count,
                       SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) AS error_count
                FROM api_logs
                GROUP BY endpoint
            ) lc ON lc.endpoint = ae.endpoint_url
            WHERE ae.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $api = $stmt->fetch();

        if (!$api) {
            $out = json_encode(['success' => false, 'message' => 'API not found']);
            http_response_code(404);
            echo $out;
            $monitor->logRequest(404, null, $out);
            exit;
        }

        // Last 10 history records
        $hist = $db->prepare("
            SELECT status, response_time, error_message, check_time
            FROM api_monitoring_history
            WHERE api_id = ?
            ORDER BY check_time DESC
            LIMIT 10
        ");
        $hist->execute([$id]);

        $api['history'] = $hist->fetchAll();
        $api['usage_count'] = (int)$api['usage_count'];
        $api['error_count'] = (int)$api['error_count'];

        $out = json_encode(['success' => true, 'api' => $api]);
        echo $out;
        $monitor->logRequest(200, null, null);
        exit;
    }

    /* ────────────────── POST – test / recheck ───────────── */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? [];
        $apiId = isset($data['api_id']) ? (int)$data['api_id'] : 0;

        if ($apiId <= 0) {
            $out = json_encode(['success' => false, 'message' => 'api_id required']);
            http_response_code(422);
            echo $out;
            $monitor->logRequest(422, $raw, $out);
            exit;
        }

        $stmt = $db->prepare('SELECT id, endpoint_url FROM api_endpoints WHERE id = ? LIMIT 1');
        $stmt->execute([$apiId]);
        $api = $stmt->fetch();

        if (!$api) {
            $out = json_encode(['success' => false, 'message' => 'API not found']);
            http_response_code(404);
            echo $out;
            $monitor->logRequest(404, $raw, $out);
            exit;
        }

        $base   = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $result = ApiMonitor::pingEndpoint($base . $api['endpoint_url'], 3000);

        // Record in history
        $db->prepare("
            INSERT INTO api_monitoring_history (api_id, status, response_time, error_message, check_time)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$apiId, $result['status'], $result['response_time'], $result['error']]);

        // Update endpoint record
        $db->prepare("
            UPDATE api_endpoints SET status = ?, response_time = ?, last_check = NOW() WHERE id = ?
        ")->execute([$result['status'], (int)$result['response_time'], $apiId]);

        $out = json_encode([
            'success'       => true,
            'api_id'        => $apiId,
            'status'        => $result['status'],
            'response_time' => $result['response_time'],
            'checked_at'    => date('Y-m-d H:i:s'),
        ]);
        echo $out;
        $monitor->logRequest(200, $raw, $out);
        exit;
    }

    // Other methods
    http_response_code(405);
    $out = json_encode(['success' => false, 'message' => 'Method not allowed']);
    echo $out;
    $monitor->logRequest(405, null, $out);

} catch (PDOException $e) {
    error_log('api_monitoring.php error: ' . $e->getMessage());
    http_response_code(500);
    $out = json_encode(['success' => false, 'message' => 'Database error']);
    echo $out;
    $monitor->logRequest(500, null, $out);
}
