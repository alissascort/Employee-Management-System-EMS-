<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

session_start();

// Check if user is logged in and is CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Get latest performance metrics
    $stmt = $conn->prepare("
        SELECT 
            metric_name,
            metric_value,
            unit,
            recorded_at
        FROM system_performance 
        WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ORDER BY recorded_at DESC
    ");
    $stmt->execute();
    $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get current active sessions
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT session_id) as active_sessions
        FROM user_sessions 
        WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    $stmt->execute();
    $sessions = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get current error rate from API logs
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_requests
        FROM api_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute();
    $apiStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate error rate
    $errorRate = 0;
    if ($apiStats['total_requests'] > 0) {
        $errorRate = round(($apiStats['error_requests'] / $apiStats['total_requests']) * 100, 1);
    }
    
    // Get average response time
    $stmt = $conn->prepare("
        SELECT AVG(response_time) as avg_response_time
        FROM api_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute();
    $responseTime = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Format performance data
    $performance = [
        'response_time' => round($responseTime['avg_response_time'] ?? 0, 1),
        'error_rate' => $errorRate,
        'active_sessions' => $sessions['active_sessions'] ?? 0,
        'system_load' => 0,
        'memory_usage' => 0,
        'cpu_usage' => 0,
        'disk_usage' => 0
    ];
    
    // Get latest metrics from system_performance table
    foreach ($metrics as $metric) {
        if (isset($performance[$metric['metric_name']])) {
            $performance[$metric['metric_name']] = round($metric['metric_value'], 1);
        }
    }
    
    // Use real system data if no historical data exists
    $loadAverage = sys_getloadavg();
    $memoryUsage = memory_get_usage(true);
    $diskTotal = disk_total_space('/');
    $diskFree = disk_free_space('/');
    
    if ($performance['system_load'] == 0) {
        $performance['system_load'] = round($loadAverage[0] * 50, 1);
    }
    if ($performance['memory_usage'] == 0) {
        $performance['memory_usage'] = round(($memoryUsage / 134217728) * 100, 1);
    }
    if ($performance['cpu_usage'] == 0) {
        $performance['cpu_usage'] = round($loadAverage[0] * 50, 1);
    }
    if ($performance['disk_usage'] == 0) {
        $performance['disk_usage'] = round((($diskTotal - $diskFree) / $diskTotal) * 100, 1);
    }
    
    echo json_encode([
        'success' => true,
        'performance' => $performance,
        'last_updated' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("System performance error: " . $e->getMessage());
    
    // Return error if database fails
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load real system metrics: ' . $e->getMessage(),
        'error' => true
    ]);
}
?> 