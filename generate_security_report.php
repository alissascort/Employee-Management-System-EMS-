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
    $report_id = 'SEC-' . date('Ymd') . '-' . rand(1000, 9999);
    
    // Generate comprehensive security report
    $report_data = [
        'report_id' => $report_id,
        'generated_at' => $current_time,
        'generated_by' => $_SESSION['user_id'],
        'period' => 'Last 24 Hours',
        'summary' => [],
        'details' => []
    ];
    
    // Get system status summary
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_alerts,
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_alerts,
            SUM(CASE WHEN severity = 'warning' THEN 1 ELSE 0 END) as warning_alerts,
            SUM(CASE WHEN severity = 'info' THEN 1 ELSE 0 END) as info_alerts
        FROM security_alerts 
        WHERE DATE(created_at) = ?
    ");
    $stmt->execute([$current_date]);
    $alert_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get active users count
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT user_id) as active_users 
        FROM user_sessions 
        WHERE last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    $stmt->execute();
    $active_users = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get system performance metrics
    $stmt = $conn->prepare("
        SELECT 
            AVG(response_time) as avg_response,
            (COUNT(CASE WHEN status_code >= 400 THEN 1 END) * 100.0 / COUNT(*)) as error_rate,
            COUNT(*) as total_requests
        FROM api_logs 
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute();
    $performance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent security alerts
    $stmt = $conn->prepare("
        SELECT 
            sa.severity,
            sa.alert_type,
            sa.description,
            sa.created_at,
            sa.user_id
        FROM security_alerts sa
        WHERE DATE(sa.created_at) = ?
        ORDER BY sa.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$current_date]);
    $recent_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user names from appropriate tables
    foreach ($recent_alerts as &$alert) {
        if ($alert['user_id']) {
            $user_name = getUserName($conn, $alert['user_id']);
            $alert['user_name'] = $user_name;
        } else {
            $alert['user_name'] = 'System';
        }
    }
    
    // Get system settings status
    $stmt = $conn->prepare("
        SELECT setting_key, value, updated_at 
        FROM system_settings 
        WHERE setting_key IN ('digital_attendance_enabled', 'security_monitoring_enabled')
    ");
    $stmt->execute();
    $system_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build report summary
    $report_data['summary'] = [
        'total_alerts' => (int)$alert_summary['total_alerts'],
        'critical_alerts' => (int)$alert_summary['critical_alerts'],
        'warning_alerts' => (int)$alert_summary['warning_alerts'],
        'info_alerts' => (int)$alert_summary['info_alerts'],
        'active_users' => (int)$active_users['active_users'],
        'avg_response_time' => round($performance['avg_response'] ?? 0, 2),
        'error_rate' => round($performance['error_rate'] ?? 0, 2),
        'total_requests' => (int)$performance['total_requests'],
        'system_status' => 'Operational',
        'digital_attendance_enabled' => true
    ];
    
    // Check system status
    if ($alert_summary['critical_alerts'] > 0) {
        $report_data['summary']['system_status'] = 'Warning';
    }
    
    // Check digital attendance status
    foreach ($system_settings as $setting) {
        if ($setting['setting_key'] === 'digital_attendance_enabled') {
            $report_data['summary']['digital_attendance_enabled'] = $setting['value'] === '1';
            break;
        }
    }
    
    // Build detailed report
    $report_data['details'] = [
        'recent_alerts' => $recent_alerts,
        'system_settings' => $system_settings,
        'recommendations' => generateRecommendations($alert_summary, $performance)
    ];
    
    // Save report to database
    $stmt = $conn->prepare("
        INSERT INTO security_reports (
            report_id, 
            created_by, 
            report_data, 
            created_at
        ) VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $report_id,
        $_SESSION['user_id'],
        json_encode($report_data),
        $current_time
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Security report generated successfully',
        'report_id' => $report_id,
        'report_data' => $report_data
    ]);
    
} catch (Exception $e) {
    error_log("Security report generation error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to generate security report: ' . $e->getMessage()
    ]);
}

function getUserName($conn, $user_id) {
    // Check in different user tables with their specific ID columns
    $table_configs = [
        ['table' => 'admins', 'id_column' => 'admin_id'],
        ['table' => 'employees', 'id_column' => 'employee_id'],
        ['table' => 'csos', 'id_column' => 'cso_id'],
        ['table' => 'hr', 'id_column' => 'hr_id'],
        ['table' => 'dept_managers', 'id_column' => 'manager_id']
    ];
    
    foreach ($table_configs as $config) {
        try {
            $stmt = $conn->prepare("SELECT full_name FROM {$config['table']} WHERE {$config['id_column']} = ?");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['full_name']) {
                return $result['full_name'];
            }
        } catch (Exception $e) {
            // Continue to next table if this one doesn't have the column
            continue;
        }
    }
    
    return 'Unknown User';
}

function generateRecommendations($alert_summary, $performance) {
    $recommendations = [];
    
    if ($alert_summary['critical_alerts'] > 0) {
        $recommendations[] = "Immediate attention required: {$alert_summary['critical_alerts']} critical alerts detected";
    }
    
    if ($alert_summary['warning_alerts'] > 5) {
        $recommendations[] = "High number of warnings: Consider reviewing system configuration";
    }
    
    if (($performance['error_rate'] ?? 0) > 5) {
        $recommendations[] = "High error rate detected: Investigate system performance issues";
    }
    
    if (($performance['avg_response'] ?? 0) > 1000) {
        $recommendations[] = "Slow response times: Consider system optimization";
    }
    
    if (empty($recommendations)) {
        $recommendations[] = "System operating normally - no immediate action required";
    }
    
    return $recommendations;
}
?> 
