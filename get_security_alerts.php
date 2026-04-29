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
    
    // Get filter parameters
    $severity_filter = $_GET['severity'] ?? '';
    $type_filter = $_GET['type'] ?? '';
    $limit = 50;
    
    // Build query with FIXED JOIN condition
    $query = "
        SELECT 
            sa.id,
            sa.severity,
            sa.alert_type,
            sa.description,
            sa.user_id,
            sa.created_at,
            sa.status,
            COALESCE(u.full_name, 'System') as user_name,
            COALESCE(u.user_type, 'system') as user_type
        FROM security_alerts sa
        LEFT JOIN users u ON sa.user_id = u.user_id  -- FIXED: Changed u.id to u.user_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($severity_filter) {
        $query .= " AND sa.severity = ?";
        $params[] = $severity_filter;
    }
    
    if ($type_filter) {
        $query .= " AND sa.alert_type = ?";
        $params[] = $type_filter;
    }
    
    $query .= " ORDER BY sa.created_at DESC LIMIT " . (int)$limit;
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format alerts for display
    $formatted_alerts = [];
    foreach ($alerts as $alert) {
        $formatted_alerts[] = [
            'id' => $alert['id'],
            'time' => date('H:i:s', strtotime($alert['created_at'])),
            'date' => date('Y-m-d', strtotime($alert['created_at'])),
            'severity' => $alert['severity'],
            'type' => $alert['alert_type'],
            'description' => $alert['description'],
            'user' => $alert['user_name'] ?? 'System',
            'status' => $alert['status'],
            'action' => getActionButton($alert['severity'], $alert['id'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'alerts' => $formatted_alerts,
        'total' => count($formatted_alerts)
    ]);
    
} catch (Exception $e) {
    error_log("Security alerts error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'alerts' => []
    ]);
}

function getActionButton($severity, $alert_id) {
    switch ($severity) {
        case 'critical':
            return "<button class='btn btn-danger btn-sm' onclick='investigateAlert($alert_id)'>Investigate</button>";
        case 'warning':
            return "<button class='btn btn-warning btn-sm' onclick='acknowledgeAlert($alert_id)'>Acknowledge</button>";
        default:
            return "<button class='btn btn-info btn-sm' onclick='viewAlert($alert_id)'>View</button>";
    }
}
?>