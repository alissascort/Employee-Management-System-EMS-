<?php
require_once 'vendor/autoload.php';
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

use Dompdf\Dompdf;
use Dompdf\Options;

session_start();

// Check if user is logged in as CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    http_response_code(403);
    exit('Unauthorized access');
}

if (!isset($_GET['audit_id'])) {
    http_response_code(400);
    exit('Audit ID is required');
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
        http_response_code(404);
        exit('Audit not found');
    }
    
    // Get detailed findings
    $stmt = $conn->prepare("
        SELECT af.*
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
    
    // Generate HTML for PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Security Audit Report - ' . htmlspecialchars($audit['title']) . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 30px; }
            .header h1 { color: #2c3e50; margin: 0; }
            .header p { color: #7f8c8d; margin: 5px 0; }
            .section { margin-bottom: 30px; }
            .section h2 { color: #34495e; border-bottom: 1px solid #bdc3c7; padding-bottom: 10px; }
            .info-grid { display: table; width: 100%; margin-bottom: 20px; }
            .info-row { display: table-row; }
            .info-label { display: table-cell; font-weight: bold; width: 150px; padding: 8px; background: #ecf0f1; }
            .info-value { display: table-cell; padding: 8px; border-bottom: 1px solid #ecf0f1; }
            .findings-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            .findings-table th, .findings-table td { border: 1px solid #bdc3c7; padding: 12px; text-align: left; }
            .findings-table th { background: #34495e; color: white; }
            .severity-critical { background: #e74c3c; color: white; font-weight: bold; }
            .severity-high { background: #e67e22; color: white; font-weight: bold; }
            .severity-medium { background: #f39c12; color: white; font-weight: bold; }
            .severity-low { background: #27ae60; color: white; font-weight: bold; }
            .status-open { background: #e74c3c; color: white; }
            .status-in_progress { background: #f39c12; color: white; }
            .status-resolved { background: #27ae60; color: white; }
            .summary { background: #f8f9fa; padding: 15px; border-left: 4px solid #3498db; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Security Audit Report</h1>
            <p>Generated on: ' . date('Y-m-d H:i:s') . '</p>
            <p>Report ID: AUDIT-' . str_pad($audit['id'], 6, '0', STR_PAD_LEFT) . '</p>
        </div>
        
        <div class="section">
            <h2>Audit Information</h2>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Audit Title:</div>
                    <div class="info-value">' . htmlspecialchars($audit['title']) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Conducted By:</div>
                    <div class="info-value">' . htmlspecialchars($audit['conducted_by']) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Audit Date:</div>
                    <div class="info-value">' . htmlspecialchars($audit['date']) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Total Findings:</div>
                    <div class="info-value">' . $audit['total_findings'] . '</div>
                </div>
            </div>
        </div>
        
        <div class="section">
            <h2>Executive Summary</h2>
            <div class="summary">
                ' . htmlspecialchars($audit['summary']) . '
            </div>
        </div>
        
        <div class="section">
            <h2>Findings Summary</h2>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Critical Issues:</div>
                    <div class="info-value">' . $audit['critical_findings'] . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">High Issues:</div>
                    <div class="info-value">' . $audit['high_findings'] . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Medium Issues:</div>
                    <div class="info-value">' . $audit['medium_findings'] . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Low Issues:</div>
                    <div class="info-value">' . $audit['low_findings'] . '</div>
                </div>
            </div>
        </div>';
    
    if (!empty($findings)) {
        $html .= '
        <div class="section">
            <h2>Detailed Findings</h2>
            <table class="findings-table">
                <thead>
                    <tr>
                        <th>Severity</th>
                        <th>Description</th>
                        <th>Recommendation</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($findings as $finding) {
            $severity_class = 'severity-' . $finding['severity'];
            $status_class = 'status-' . $finding['status'];
            
            $html .= '
                    <tr>
                        <td class="' . $severity_class . '">' . ucfirst(htmlspecialchars($finding['severity'])) . '</td>
                        <td>' . htmlspecialchars($finding['description']) . '</td>
                        <td>' . htmlspecialchars($finding['recommendation']) . '</td>
                        <td class="' . $status_class . '">' . ucfirst(str_replace('_', ' ', htmlspecialchars($finding['status']))) . '</td>
                    </tr>';
        }
        
        $html .= '
                </tbody>
            </table>
        </div>';
    }
    
    $html .= '
        <div class="section">
            <h2>Recommendations</h2>
            <p>Based on the findings of this security audit, the following actions are recommended:</p>
            <ul>';
    
    if ($audit['critical_findings'] > 0) {
        $html .= '<li><strong>Immediate Action Required:</strong> Address all critical security issues identified in this audit.</li>';
    }
    if ($audit['high_findings'] > 0) {
        $html .= '<li><strong>High Priority:</strong> Resolve high-severity findings within 30 days.</li>';
    }
    if ($audit['medium_findings'] > 0) {
        $html .= '<li><strong>Medium Priority:</strong> Address medium-severity findings within 60 days.</li>';
    }
    if ($audit['low_findings'] > 0) {
        $html .= '<li><strong>Low Priority:</strong> Consider addressing low-severity findings during regular maintenance.</li>';
    }
    
    $html .= '
            </ul>
        </div>
        
        <div class="section">
            <p><strong>Report generated by:</strong> ' . htmlspecialchars($audit['conducted_by']) . '</p>
            <p><strong>Date:</strong> ' . date('Y-m-d H:i:s') . '</p>
        </div>
    </body>
    </html>';
    
    // Configure DOMPDF
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Output PDF
    $filename = 'Security_Audit_Report_' . str_pad($audit['id'], 6, '0', STR_PAD_LEFT) . '_' . date('Y-m-d') . '.pdf';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    echo $dompdf->output();
    
} catch (Exception $e) {
    error_log("PDF generation error: " . $e->getMessage());
    http_response_code(500);
    exit('Error generating PDF report');
}
?> 