<?php
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Check if user is logged in as CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    error_log("CSO Dashboard Access Denied - user_id: " . ($_SESSION['user_id'] ?? 'not set') . ", user_type: " . ($_SESSION['user_type'] ?? 'not set'));
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    $cso_id = $_SESSION['user_id'];
    $dashboard_data = [];
    
    // 1. Security Incidents - Add error handling
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_incidents,
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_incidents,
                SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_incidents,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_incidents
            FROM security_incidents 
            WHERE DATE(reported_at) = CURDATE()
        ");
        $stmt->execute();
        $incidents = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Security incidents query error: " . $e->getMessage());
        $incidents = ['total_incidents' => 0, 'critical_incidents' => 0, 'high_incidents' => 0, 'open_incidents' => 0];
    }
    
    // 2. Active Patrols - Add error handling
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as active_patrols,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as currently_active,
                SUM(checkpoints_completed) as total_checkpoints_completed,
                SUM(total_checkpoints) as total_checkpoints
            FROM active_patrols 
            WHERE cso_id = ? AND DATE(start_time) = CURDATE()
        ");
        $stmt->execute([$cso_id]);
        $patrols = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Active patrols query error: " . $e->getMessage());
        $patrols = ['active_patrols' => 0, 'currently_active' => 0, 'total_checkpoints_completed' => 0, 'total_checkpoints' => 0];
    }
    
    // 3. Security Audits - Add error handling
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_audits,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_audits,
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_findings,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as ongoing_audits
            FROM security_audits 
            WHERE auditor_id = ? AND DATE(audit_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$cso_id]);
        $audits = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Security audits query error: " . $e->getMessage());
        $audits = ['total_audits' => 0, 'completed_audits' => 0, 'critical_findings' => 0, 'ongoing_audits' => 0];
    }
    
    // 4. System Logs - Add error handling
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_logs,
                SUM(CASE WHEN log_level = 'ERROR' THEN 1 ELSE 0 END) as error_logs,
                SUM(CASE WHEN log_level = 'WARNING' THEN 1 ELSE 0 END) as warning_logs,
                SUM(CASE WHEN log_type = 'security' THEN 1 ELSE 0 END) as security_logs
            FROM system_logs 
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $logs = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("System logs query error: " . $e->getMessage());
        $logs = ['total_logs' => 0, 'error_logs' => 0, 'warning_logs' => 0, 'security_logs' => 0];
    }
    
    // 5. Vulnerabilities - Add error handling
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_vulnerabilities,
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_vulns,
                SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_vulns,
                SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium_vulns,
                SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low_vulns,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_vulns
            FROM vulnerability_scans 
            WHERE DATE(scan_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $vulnerabilities = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Vulnerabilities query error: " . $e->getMessage());
        $vulnerabilities = ['total_vulnerabilities' => 0, 'critical_vulns' => 0, 'high_vulns' => 0, 'medium_vulns' => 0, 'low_vulns' => 0, 'open_vulns' => 0];
    }
    
    // 6. API Monitoring - Add error handling
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_apis,
                SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_apis,
                SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_apis,
                SUM(CASE WHEN status = 'slow' THEN 1 ELSE 0 END) as slow_apis,
                AVG(response_time) as avg_response_time
            FROM api_endpoints 
            WHERE is_active = 1
        ");
        $stmt->execute();
        $apis = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("API monitoring query error: " . $e->getMessage());
        $apis = ['total_apis' => 0, 'up_apis' => 0, 'down_apis' => 0, 'slow_apis' => 0, 'avg_response_time' => 0];
    }
    
    // 7. Recent CSO Activities - Add error handling
    try {
        $stmt = $conn->prepare("
            SELECT activity_type, description, activity_date 
            FROM cso_activity_logs 
            WHERE cso_id = ? 
            ORDER BY activity_date DESC 
            LIMIT 5
        ");
        $stmt->execute([$cso_id]);
        $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Recent activities query error: " . $e->getMessage());
        $recent_activities = [];
    }
    
    // 8. Critical Alerts (High priority items)
    $critical_alerts = [];
    
    // Critical incidents
    if ($incidents['critical_incidents'] > 0) {
        $critical_alerts[] = [
            'type' => 'critical_incident',
            'message' => $incidents['critical_incidents'] . ' critical security incident(s) require immediate attention',
            'priority' => 'critical'
        ];
    }
    
    // Down APIs
    if ($apis['down_apis'] > 0) {
        $critical_alerts[] = [
            'type' => 'api_down',
            'message' => $apis['down_apis'] . ' API endpoint(s) are down',
            'priority' => 'high'
        ];
    }
    
    // Open vulnerabilities
    if ($vulnerabilities['open_vulns'] > 0) {
        $critical_alerts[] = [
            'type' => 'open_vulnerability',
            'message' => $vulnerabilities['open_vulns'] . ' vulnerability(ies) need immediate patching',
            'priority' => 'high'
        ];
    }
    
    // Compile dashboard data
    $dashboard_data = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'cards' => [
            'security_incidents' => [
                'total' => (int)$incidents['total_incidents'],
                'critical' => (int)$incidents['critical_incidents'],
                'high' => (int)$incidents['high_incidents'],
                'open' => (int)$incidents['open_incidents']
            ],
            'active_patrols' => [
                'total' => (int)$patrols['active_patrols'],
                'currently_active' => (int)$patrols['currently_active'],
                'checkpoints_completed' => (int)$patrols['total_checkpoints_completed'],
                'total_checkpoints' => (int)$patrols['total_checkpoints']
            ],
            'security_audits' => [
                'total' => (int)$audits['total_audits'],
                'completed' => (int)$audits['completed_audits'],
                'critical_findings' => (int)$audits['critical_findings'],
                'ongoing' => (int)$audits['ongoing_audits']
            ],
            'system_logs' => [
                'total_24h' => (int)$logs['total_logs'],
                'errors' => (int)$logs['error_logs'],
                'warnings' => (int)$logs['warning_logs'],
                'security_logs' => (int)$logs['security_logs']
            ],
            'vulnerabilities' => [
                'total' => (int)$vulnerabilities['total_vulnerabilities'],
                'critical' => (int)$vulnerabilities['critical_vulns'],
                'high' => (int)$vulnerabilities['high_vulns'],
                'medium' => (int)$vulnerabilities['medium_vulns'],
                'low' => (int)$vulnerabilities['low_vulns'],
                'open' => (int)$vulnerabilities['open_vulns']
            ],
            'api_monitoring' => [
                'total' => (int)$apis['total_apis'],
                'up' => (int)$apis['up_apis'],
                'down' => (int)$apis['down_apis'],
                'slow' => (int)$apis['slow_apis'],
                'avg_response_time' => round($apis['avg_response_time'], 2)
            ]
        ],
        'recent_activities' => $recent_activities,
        'critical_alerts' => $critical_alerts
    ];
    
    echo json_encode($dashboard_data);
    
} catch (Exception $e) {
    error_log("CSO dashboard data error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
}
?> 