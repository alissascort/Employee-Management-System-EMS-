<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Check if user is logged in and is CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    $current_date = date('Y-m-d');
    $current_time = date('Y-m-d H:i:s');
    
    // Get system status data
    $status_data = [
        'system_status' => 'operational',
        'last_check' => $current_time,
        'active_users' => 0,
        'security_alerts' => 0,
        'system_health'   => 100,
        'response_time'   => 0,
        'error_rate'      => 0,
        'active_sessions' => 0,
        'system_load' => 0
    ];
    
    // Get active users count
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT user_id) as active_users 
        FROM user_sessions 
        WHERE last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $status_data['active_users'] = (int)$result['active_users'];
    $status_data['active_sessions'] = (int)$result['active_users'];
    
    // Get today's security alerts
    $stmt = $conn->prepare("
        SELECT COUNT(*) as alert_count 
        FROM security_alerts 
        WHERE DATE(created_at) = ? AND severity IN ('critical', 'warning')
    ");
    $stmt->execute([$current_date]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $status_data['security_alerts'] = (int)$result['alert_count'];
    
    // Get system performance metrics
    $stmt = $conn->prepare("
        SELECT 
            AVG(response_time) as avg_response,
            (COUNT(CASE WHEN status_code >= 400 THEN 1 END) * 100.0 / COUNT(*)) as error_rate
        FROM api_logs 
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $status_data['response_time'] = round($result['avg_response'] ?? 0, 2);
    $status_data['error_rate'] = round($result['error_rate'] ?? 0, 2);
    
    // Check for system issues
    $stmt = $conn->prepare("
        SELECT COUNT(*) as issue_count 
        FROM security_alerts 
        WHERE severity = 'critical' AND status = 'active'
    ");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['issue_count'] > 0) {
        $status_data['system_status'] = 'warning';
        $status_data['system_health'] = 75;
    }
    
    // Check if digital attendance is disabled
    $stmt = $conn->prepare("
        SELECT value FROM system_settings WHERE setting_key = 'digital_attendance_enabled'
    ");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['value'] === '0') {
        $status_data['system_status'] = 'disabled';
        $status_data['system_health'] = 0;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $status_data,
        'timestamp' => $current_time
    ]);
    
} catch (Exception $e) {
    error_log("Attendance system status error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'data' => [
            'system_status' => 'error',
            'last_check' => $current_time,
            'active_users' => 0,
            'security_alerts' => 0,
            'system_health' => 0,
            'response_time' => 0,
            'error_rate' => 100,
            'active_sessions' => 0,
            'system_load' => 0
        ]
    ]);
}
?> 