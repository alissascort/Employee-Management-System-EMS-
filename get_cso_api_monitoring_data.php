<?php
/**
 * get_cso_api_monitoring_data.php
 * ─────────────────────────────────────────────────────────
 * Returns complete API monitoring data for the CSO dashboard.
 *
 * Response shape:
 * {
 *   success: true,
 *   apis: [...],
 *   stats: { apiUpCount, apiDownCount, apiSlowCount, apiTotalCount, errorRate },
 *   top_used: [...],
 *   recent_errors: [...]
 * }
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
    $out = json_encode(['success' => false, 'message' => 'Unauthorized access']);
    echo $out;
    $monitor->logRequest(401, null, $out);
    exit;
}

// ── DB ────────────────────────────────────────────────────
try {
    $db = new PDO(
        'mysql:host=localhost;dbname=employee_management_system;charset=utf8mb4',
        'ems_user', 'securepassword123',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    /* ── 1. API list with latest status ─────────────────── */
    $apisRaw = $db->query("
        SELECT
            ae.id,
            ae.api_name        AS name,
            ae.endpoint_url    AS endpoint,
            ae.status,
            ae.response_time,
            ae.last_check,
            ae.is_active,
            ae.created_at,
            COALESCE(lc.usage_count, 0)   AS usage_count,
            COALESCE(lc.last_method, 'GET') AS method
        FROM api_endpoints ae
        LEFT JOIN (
            SELECT endpoint,
                   COUNT(*)                                        AS usage_count,
                   MAX(method)                                     AS last_method
            FROM api_logs
            GROUP BY endpoint
        ) lc ON lc.endpoint = ae.endpoint_url
        ORDER BY ae.id ASC
    ")->fetchAll();

    $apis = [];
    $upCount   = 0;
    $downCount = 0;
    $slowCount = 0;

    foreach ($apisRaw as $row) {
        $s = strtolower($row['status'] ?? 'up');
        if (!$row['is_active']) $s = 'disabled';

        match ($s) {
            'up'   => $upCount++,
            'slow' => $slowCount++,
            'down','disabled' => $downCount++,
            default => null,
        };

        $apis[] = [
            'id'            => (int)$row['id'],
            'name'          => $row['name'],
            'endpoint'      => $row['endpoint'],
            'method'        => strtoupper($row['method']),
            'status'        => $s,
            'response_time' => (int)$row['response_time'],
            'last_check'    => $row['last_check'],
            'usage_count'   => (int)$row['usage_count'],
            'is_active'     => (bool)$row['is_active'],
        ];
    }

    $totalApis = count($apis);

    /* ── 2. Error rate (last 24 h) ──────────────────────── */
    $errRow = $db->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) AS errors
        FROM api_logs
        WHERE created_at >= NOW() - INTERVAL 1 DAY
    ")->fetch();

    $errorRate = 0;
    if ($errRow && $errRow['total'] > 0) {
        $errorRate = round(($errRow['errors'] / $errRow['total']) * 100, 1);
    }

    /* ── 3. Top 5 used APIs ─────────────────────────────── */
    $topUsed = $db->query("
        SELECT
            al.endpoint,
            COALESCE(ae.api_name, al.endpoint) AS name,
            COUNT(*)               AS request_count,
            AVG(al.response_time)  AS avg_response,
            MAX(al.created_at)     AS last_used
        FROM api_logs al
        LEFT JOIN api_endpoints ae ON ae.endpoint_url = al.endpoint
        WHERE al.created_at >= NOW() - INTERVAL 7 DAY
        GROUP BY al.endpoint, ae.api_name
        ORDER BY request_count DESC
        LIMIT 5
    ")->fetchAll();

    $topFormatted = array_map(fn($r) => [
        'endpoint'      => $r['endpoint'],
        'name'          => $r['name'],
        'request_count' => (int)$r['request_count'],
        'avg_response'  => round((float)$r['avg_response'], 1),
        'last_used'     => $r['last_used'],
    ], $topUsed);

    /* ── 4. Recent 10 errors ────────────────────────────── */
    $recentErrors = $db->query("
        SELECT
            al.id,
            al.endpoint,
            al.method,
            al.status_code,
            al.response_time,
            al.ip_address,
            al.user_type,
            al.created_at,
            COALESCE(ae.api_name, al.endpoint) AS api_name
        FROM api_logs al
        LEFT JOIN api_endpoints ae ON ae.endpoint_url = al.endpoint
        WHERE al.status_code >= 400
        ORDER BY al.created_at DESC
        LIMIT 10
    ")->fetchAll();

    $errFormatted = array_map(fn($r) => [
        'id'            => (int)$r['id'],
        'api_name'      => $r['api_name'],
        'endpoint'      => $r['endpoint'],
        'method'        => $r['method'],
        'status_code'   => (int)$r['status_code'],
        'response_time' => round((float)$r['response_time'], 1),
        'ip_address'    => $r['ip_address'],
        'user_type'     => $r['user_type'],
        'created_at'    => $r['created_at'],
    ], $recentErrors);

    /* ── 5. Assemble response ────────────────────────────── */
    $out = json_encode([
        'success' => true,
        'apis'    => $apis,
        'stats'   => [
            'apiUpCount'    => $upCount,
            'apiDownCount'  => $downCount,
            'apiSlowCount'  => $slowCount,
            'apiTotalCount' => $totalApis,
            'errorRate'     => $errorRate,
        ],
        'top_used'      => $topFormatted,
        'recent_errors' => $errFormatted,
        'generated_at'  => date('Y-m-d H:i:s'),
    ]);

    echo $out;
    $monitor->logRequest(200, null, null);

} catch (PDOException $e) {
    error_log('get_cso_api_monitoring_data error: ' . $e->getMessage());
    http_response_code(500);
    $out = json_encode(['success' => false, 'message' => 'Database error occurred']);
    echo $out;
    $monitor->logRequest(500, null, $out);
}
