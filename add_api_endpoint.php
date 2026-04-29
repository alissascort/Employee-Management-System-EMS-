<?php
/**
 * add_api_endpoint.php
 * Registers a new API endpoint into api_endpoints.
 * CSO-only. Integrated with ApiMonitor.
 */
require_once __DIR__ . '/api_monitoring1.php';

header('Content-Type: application/json');

$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// ── Session / auth guard ──────────────────────────────────
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $out = json_encode(['success' => false, 'message' => 'Method not allowed']);
    echo $out;
    $monitor->logRequest(405, null, $out);
    exit;
}

// ── Input ─────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

$name     = trim($data['name']     ?? $data['api_name']     ?? '');
$endpoint = trim($data['endpoint'] ?? $data['endpoint_url'] ?? '');

if ($name === '' || $endpoint === '') {
    http_response_code(422);
    $out = json_encode(['success' => false, 'message' => 'api_name and endpoint_url are required']);
    echo $out;
    $monitor->logRequest(422, $raw, $out);
    exit;
}

// Normalise endpoint to start with /
if ($endpoint[0] !== '/') $endpoint = '/' . $endpoint;

// ── DB ────────────────────────────────────────────────────
try {
    $db = new PDO(
        'mysql:host=localhost;dbname=employee_management_system;charset=utf8mb4',
        'ems_user', 'securepassword123',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $db->prepare("
        INSERT IGNORE INTO api_endpoints (api_name, endpoint_url, is_active, created_at)
        VALUES (?, ?, 1, NOW())
    ");
    $stmt->execute([$name, $endpoint]);

    if ($stmt->rowCount() === 0) {
        // Already exists — just return success
        $stmt2 = $db->prepare('SELECT id FROM api_endpoints WHERE endpoint_url = ? LIMIT 1');
        $stmt2->execute([$endpoint]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);
        $out = json_encode([
            'success'  => true,
            'message'  => 'API endpoint already registered',
            'id'       => $row['id'] ?? null,
            'endpoint' => $endpoint,
        ]);
    } else {
        $out = json_encode([
            'success'  => true,
            'message'  => 'API endpoint added successfully',
            'id'       => $db->lastInsertId(),
            'endpoint' => $endpoint,
        ]);
    }

    echo $out;
    $monitor->logRequest(200, $raw, $out);

} catch (PDOException $e) {
    error_log('add_api_endpoint error: ' . $e->getMessage());
    http_response_code(500);
    $out = json_encode(['success' => false, 'message' => 'Database error']);
    echo $out;
    $monitor->logRequest(500, $raw, $out);
}
