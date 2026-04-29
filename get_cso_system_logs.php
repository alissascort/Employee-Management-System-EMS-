<?php
session_start();
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

header('Content-Type: application/json');

if (!isset($_SESSION['cso_logged_in']) || $_SESSION['cso_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();

    // Build query with filters
    $whereConditions = ["1=1"];
    $params = [];

    // Type filter
    if (isset($_GET['type']) && $_GET['type'] !== 'all') {
        $whereConditions[] = "log_type = :log_type";
        $params[':log_type'] = $_GET['type'];
    }

    // Date range filters
    if (isset($_GET['from']) && !empty($_GET['from'])) {
        $whereConditions[] = "timestamp >= :date_from";
        $params[':date_from'] = $_GET['from'];
    }

    if (isset($_GET['to']) && !empty($_GET['to'])) {
        $whereConditions[] = "timestamp <= :date_to";
        $params[':date_to'] = $_GET['to'] . ' 23:59:59';
    }

    // Search filter
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $whereConditions[] = "(message LIKE :search OR log_type LIKE :search)";
        $params[':search'] = '%' . $_GET['search'] . '%';
    }

    $whereClause = implode(' AND ', $whereConditions);

    $sql = "SELECT 
                id,
                log_level as level,
                log_type as type,
                message,
                timestamp,
                created_at
            FROM system_logs 
            WHERE $whereClause 
            ORDER BY timestamp DESC 
            LIMIT 1000";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'total' => count($logs)
    ]);

} catch (PDOException $e) {
    error_log("Database error in get_cso_system_logs: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
