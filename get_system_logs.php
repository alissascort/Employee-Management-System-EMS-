<?php
session_start();
header('Content-Type: application/json');

// Database connection
require_once 'db_connect.php';

// Check authentication
if (!isset($_SESSION['employee_id']) && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $type = $_GET['type'] ?? 'all';
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    $search = $_GET['search'] ?? '';
    $limit = $_GET['limit'] ?? 50;
    
    $query = "SELECT sl.*, e.first_name, e.last_name, e.email 
              FROM system_logs sl 
              LEFT JOIN employees e ON sl.user_id = e.id 
              WHERE 1=1";
    $params = [];
    
    if ($type !== 'all') {
        $query .= " AND sl.type = ?";
        $params[] = $type;
    }
    
    if ($from) {
        $query .= " AND sl.timestamp >= ?";
        $params[] = $from;
    }
    
    if ($to) {
        $query .= " AND sl.timestamp <= ?";
        $params[] = $to;
    }
    
    if ($search) {
        $query .= " AND (sl.message LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $query .= " ORDER BY sl.timestamp DESC LIMIT ?";
    $params[] = (int)$limit;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format logs for display
    $formattedLogs = [];
    foreach ($logs as $log) {
        $details = json_decode($log['details'], true);
        $formattedLogs[] = [
            'id' => $log['id'],
            'type' => $log['type'],
            'message' => $log['message'],
            'user_name' => $log['first_name'] ? $log['first_name'] . ' ' . $log['last_name'] : 'System',
            'user_email' => $log['email'] ?? 'N/A',
            'timestamp' => $log['timestamp'],
            'details' => $details,
            'formatted_time' => date('M j, Y g:i A', strtotime($log['timestamp']))
        ];
    }
    
    echo json_encode(['success' => true, 'logs' => $formattedLogs]);
    
} catch (Exception $e) {
    error_log("Error fetching system logs: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to fetch logs']);
}
?>
