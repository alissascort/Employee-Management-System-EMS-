<?php
/**
 * api_monitoring_engine.php
 * ─────────────────────────────────────────────────────────
 * Monitoring Engine – designed to be run as a cron job:
 *   * * * * * php /path/to/api_monitoring_engine.php >> /var/log/api_monitor.log 2>&1
 *
 * It also supports being called via HTTP (CSO dashboard "Recheck All").
 */

require_once __DIR__ . '/api_monitoring1.php';

// ── Allow HTTP calls from CSO only ────────────────────────
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');

    if (session_status() === PHP_SESSION_NONE) session_start();

    if (
        !isset($_SESSION['user_id']) ||
        !in_array($_SESSION['user_type'] ?? '', ['cso', 'admin'], true)
    ) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

// ── DB connection ─────────────────────────────────────────
try {
    $db = new PDO(
        'mysql:host=localhost;dbname=employee_management_system;charset=utf8mb4',
        'ems_user', 'securepassword123',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    $msg = 'DB connection failed: ' . $e->getMessage();
    if (PHP_SAPI === 'cli') {
        echo "[ERROR] $msg\n";
    } else {
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit(1);
}

// ── Determine base URL for internal pings ─────────────────
$baseUrl = getenv('APP_BASE_URL') ?: 'http://localhost';

// ── Fetch all active APIs ─────────────────────────────────
$stmt = $db->query("SELECT id, api_name, endpoint_url FROM api_endpoints WHERE is_active = 1");
$apis = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results   = [];
$upCount   = 0;
$downCount = 0;
$slowCount = 0;

foreach ($apis as $api) {
    $url    = $baseUrl . $api['endpoint_url'];
    $result = ApiMonitor::pingEndpoint($url, 2500);

    // Insert into monitoring history
    $ins = $db->prepare("
        INSERT INTO api_monitoring_history (api_id, status, response_time, error_message, check_time)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $ins->execute([
        $api['id'],
        $result['status'],
        round($result['response_time'], 2),
        $result['error'],
    ]);

    // Update api_endpoints with latest check result
    $db->prepare("
        UPDATE api_endpoints
        SET status = ?, response_time = ?, last_check = NOW()
        WHERE id = ?
    ")->execute([$result['status'], (int)$result['response_time'], $api['id']]);

    // Tally
    match ($result['status']) {
        'up'   => $upCount++,
        'slow' => $slowCount++,
        default => $downCount++,
    };

    $results[] = [
        'id'            => $api['id'],
        'name'          => $api['api_name'],
        'endpoint'      => $api['endpoint_url'],
        'status'        => $result['status'],
        'response_time' => $result['response_time'],
        'error'         => $result['error'],
    ];

    // CLI progress
    if (PHP_SAPI === 'cli') {
        $symbol = match($result['status']) { 'up' => '✓', 'slow' => '~', default => '✗' };
        echo sprintf(
            "[%s] %s %-40s %6.1fms  %s\n",
            date('H:i:s'),
            $symbol,
            $api['endpoint_url'],
            $result['response_time'],
            $result['error'] ?? ''
        );
    }
}

// ── Prune history older than 30 days ─────────────────────
$db->exec("DELETE FROM api_monitoring_history WHERE check_time < NOW() - INTERVAL 30 DAY");

// ── Output ────────────────────────────────────────────────
$summary = [
    'success'    => true,
    'checked_at' => date('Y-m-d H:i:s'),
    'total'      => count($apis),
    'up'         => $upCount,
    'slow'       => $slowCount,
    'down'       => $downCount,
    'results'    => $results,
];

if (PHP_SAPI === 'cli') {
    echo "\n[SUMMARY] total={$summary['total']} up=$upCount slow=$slowCount down=$downCount\n";
} else {
    echo json_encode($summary);
}
