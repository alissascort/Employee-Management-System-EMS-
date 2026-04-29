<?php
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Check if user is logged in as CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    $cso_id = $_SESSION['user_id'];
    
    // Get query parameters
    $search = $_GET['search'] ?? '';
    $type = $_GET['type'] ?? 'all';
    $date = $_GET['date'] ?? '';
    
    // Build query for audits table
    $query = "SELECT a.*, 
              COUNT(af.id) as findings_count,
              SUM(CASE WHEN af.severity = 'critical' THEN 1 ELSE 0 END) as critical_count
              FROM audits a 
              LEFT JOIN audit_findings af ON a.id = af.audit_id 
              WHERE a.conducted_by = ?";
    $params = [$cso_id];
    
    if ($search) {
        $query .= " AND (a.title LIKE ? OR a.summary LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($type !== 'all') {
        $query .= " AND a.title LIKE ?";
        $params[] = "%$type%";
    }
    
    if ($date) {
        $query .= " AND DATE(a.date) = ?";
        $params[] = $date;
    }
    
    $query .= " GROUP BY a.id ORDER BY a.date DESC LIMIT 50";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    
    $audits = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $audits[] = [
            'id' => $row['id'],
            'type' => 'Security Audit',
            'date' => $row['date'],
            'issues_found' => $row['findings_count'] > 0 ? $row['findings_count'] . ' issues found' : 'No issues found',
            'status' => $row['findings_count'] > 0 ? 'Issues Found' : 'Clean',
            'description' => $row['title'],
            'conducted_by' => $row['conducted_by'],
            'summary' => $row['summary']
        ];
    }
    
    // Get summary stats from audit_findings
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT a.id) as completed,
            SUM(CASE WHEN af.severity = 'critical' THEN 1 ELSE 0 END) as critical,
            SUM(CASE WHEN af.severity = 'high' THEN 1 ELSE 0 END) as warnings,
            SUM(CASE WHEN af.status = 'resolved' THEN 1 ELSE 0 END) as resolved
        FROM audits a
        LEFT JOIN audit_findings af ON a.id = af.audit_id
        WHERE a.conducted_by = ?
    ");
    $stmt->execute([$cso_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'audits' => $audits,
        'stats' => [
            'completedAudits' => (int)$stats['completed'],
            'criticalIssues' => (int)$stats['critical'],
            'warningIssues' => (int)$stats['warnings'],
            'resolvedIssues' => (int)$stats['resolved']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("CSO audit error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?> 