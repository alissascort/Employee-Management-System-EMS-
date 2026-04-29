<?php
header('Content-Type: application/json');

// Database connection
$db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');

// Get audit details
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = $_GET['id'];
    $stmt = $db->prepare("SELECT * FROM audits WHERE id = ?");
    $stmt->execute([$id]);
    $audit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get findings
    $stmt = $db->prepare("SELECT * FROM audit_findings WHERE audit_id = ?");
    $stmt->execute([$id]);
    $findings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'audit' => array_merge($audit, ['findings' => $findings])]);
}

// Generate PDF report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['download'])) {
    require_once('tcpdf/tcpdf.php');
    
    $id = $_GET['id'];
    // Generate PDF report using TCPDF or similar library
    // ...
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="audit_report.pdf"');
    echo $pdfContent;
    exit;
}
?>
