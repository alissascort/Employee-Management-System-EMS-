<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Get the latest audit
    $stmt = $conn->prepare("
        SELECT * FROM audits 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $latestAudit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$latestAudit) {
        echo json_encode([
            'success' => false,
            'message' => 'No audits found'
        ]);
        exit;
    }
    
    // Get findings for this audit
    $stmt = $conn->prepare("
        SELECT * FROM audit_findings 
        WHERE audit_id = ? 
        ORDER BY severity DESC, created_at ASC
    ");
    $stmt->execute([$latestAudit['id']]);
    $findings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get summary statistics
    $criticalCount = count(array_filter($findings, fn($f) => $f['severity'] === 'critical'));
    $highCount = count(array_filter($findings, fn($f) => $f['severity'] === 'high'));
    $mediumCount = count(array_filter($findings, fn($f) => $f['severity'] === 'medium'));
    $lowCount = count(array_filter($findings, fn($f) => $f['severity'] === 'low'));
    
    echo json_encode([
        'success' => true,
        'audit' => $latestAudit,
        'findings' => $findings,
        'summary' => [
            'total_findings' => count($findings),
            'critical_count' => $criticalCount,
            'high_count' => $highCount,
            'medium_count' => $mediumCount,
            'low_count' => $lowCount
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?> 