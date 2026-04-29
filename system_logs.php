<?php
header('Content-Type: application/json');

// Database connection
$db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');

// Get log entries with filters
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = $_GET['type'] ?? 'all';
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    $search = $_GET['search'] ?? '';
    
    $query = "SELECT * FROM system_logs WHERE 1=1";
    $params = [];
    
    if ($type !== 'all') {
        $query .= " AND type = ?";
        $params[] = $type;
    }
    
    if ($from) {
        $query .= " AND timestamp >= ?";
        $params[] = $from;
    }
    
    if ($to) {
        $query .= " AND timestamp <= ?";
        $params[] = $to;
    }
    
    if ($search) {
        $query .= " AND message LIKE ?";
        $params[] = "%$search%";
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'logs' => $logs]);
}

// Export logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['export'])) {
    // Generate export file (CSV, text, etc.)
    // ...
    
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="system_logs_export.txt"');
    echo $exportContent;
    exit;
}
?>
