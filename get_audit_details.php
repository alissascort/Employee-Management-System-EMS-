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

if (!isset($_GET['audit_id'])) {
    echo json_encode(['success' => false, 'message' => 'Audit ID is required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    $cso_id = $_SESSION['user_id'];
    $audit_id = $_GET['audit_id'];
    
    // Get audit details
    $stmt = $conn->prepare("
        SELECT a.*, 
               COUNT(af.id) as total_findings,
               SUM(CASE WHEN af.severity = 'critical' THEN 1 ELSE 0 END) as critical_findings,
               SUM(CASE WHEN af.severity = 'high' THEN 1 ELSE 0 END) as high_findings,
               SUM(CASE WHEN af.severity = 'medium' THEN 1 ELSE 0 END) as medium_findings,
               SUM(CASE WHEN af.severity = 'low' THEN 1 ELSE 0 END) as low_findings
        FROM audits a
        LEFT JOIN audit_findings af ON a.id = af.audit_id
        WHERE a.id = ? AND a.conducted_by = ?
        GROUP BY a.id
    ");
    $stmt->execute([$audit_id, $cso_id]);
    $audit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$audit) {
        echo json_encode(['success' => false, 'message' => 'Audit not found']);
        exit;
    }
    
    // Get detailed findings
    $stmt = $conn->prepare("
        SELECT af.*, 
               CASE 
                   WHEN af.severity = 'critical' THEN '🔴 Critical'
                   WHEN af.severity = 'high' THEN '🟠 High'
                   WHEN af.severity = 'medium' THEN '🟡 Medium'
                   WHEN af.severity = 'low' THEN '🟢 Low'
                   ELSE af.severity
               END as severity_display,
               CASE 
                   WHEN af.status = 'open' THEN '🔴 Open'
                   WHEN af.status = 'in_progress' THEN '🟡 In Progress'
                   WHEN af.status = 'resolved' THEN '🟢 Resolved'
                   ELSE af.status
               END as status_display
        FROM audit_findings af
        WHERE af.audit_id = ?
        ORDER BY 
            CASE af.severity 
                WHEN 'critical' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'medium' THEN 3 
                WHEN 'low' THEN 4 
                ELSE 5 
            END,
            af.id
    ");
    $stmt->execute([$audit_id]);
    $findings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare findings summary
    $findings_summary = [];
    if ($audit['total_findings'] > 0) {
        $findings_summary[] = "Total Issues: " . $audit['total_findings'];
        if ($audit['critical_findings'] > 0) $findings_summary[] = "Critical: " . $audit['critical_findings'];
        if ($audit['high_findings'] > 0) $findings_summary[] = "High: " . $audit['high_findings'];
        if ($audit['medium_findings'] > 0) $findings_summary[] = "Medium: " . $audit['medium_findings'];
        if ($audit['low_findings'] > 0) $findings_summary[] = "Low: " . $audit['low_findings'];
    } else {
        $findings_summary[] = "No security issues found";
    }
    
    echo json_encode([
        'success' => true,
        'audit' => [
            'id' => $audit['id'],
            'type' => 'Security Audit',
            'date' => $audit['date'],
            'conducted_by' => $audit['conducted_by'],
            'status' => $audit['total_findings'] > 0 ? 'Issues Found' : 'Clean',
            'title' => $audit['title'],
            'summary' => $audit['summary'],
            'findings_summary' => implode(', ', $findings_summary),
            'total_findings' => $audit['total_findings'],
            'critical_findings' => $audit['critical_findings'],
            'high_findings' => $audit['high_findings'],
            'medium_findings' => $audit['medium_findings'],
            'low_findings' => $audit['low_findings']
        ],
        'findings' => $findings
    ]);
    
} catch (Exception $e) {
    error_log("Audit details error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
