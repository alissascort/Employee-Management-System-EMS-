<?php
/**
 * get_api_logs.php
 * Returns paginated api_logs for a given endpoint or api_id.
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

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=employee_management_system;charset=utf8mb4',
        'ems_user', 'securepassword123',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $apiId    = isset($_GET['api_id'])  ? (int)$_GET['api_id']  : 0;
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = 20;
    $offset   = ($page - 1) * $perPage;

    // Resolve endpoint URL from api_id
    $endpoint = '';
    if ($apiId > 0) {
        $s = $db->prepare('SELECT endpoint_url FROM api_endpoints WHERE id = ? LIMIT 1');
        $s->execute([$apiId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $endpoint = $row['endpoint_url'] ?? '';
    }

    if ($endpoint === '' && $apiId === 0) {
        $out = json_encode(['success' => false, 'message' => 'api_id required']);
        echo $out;
        $monitor->logRequest(422, null, $out);
        exit;
    }

    // Count
    $countStmt = $db->prepare('SELECT COUNT(*) FROM api_logs WHERE endpoint = ?');
    $countStmt->execute([$endpoint]);
    $total = (int)$countStmt->fetchColumn();

    // Logs
    $stmt = $db->prepare("
        SELECT id, endpoint, method, user_id, user_type, ip_address,
               status_code, response_time, created_at
        FROM api_logs
        WHERE endpoint = ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $endpoint, PDO::PARAM_STR);
    $stmt->bindValue(2, $perPage,  PDO::PARAM_INT);
    $stmt->bindValue(3, $offset,   PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = json_encode([
        'success'      => true,
        'logs'         => $logs,
        'total'        => $total,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => (int)ceil($total / $perPage),
        'endpoint'     => $endpoint,
    ]);
    echo $out;
    $monitor->logRequest(200, null, null);

} catch (PDOException $e) {
    error_log('get_api_logs error: ' . $e->getMessage());
    http_response_code(500);
    $out = json_encode(['success' => false, 'message' => 'Database error']);
    echo $out;
    $monitor->logRequest(500, null, $out);
}
