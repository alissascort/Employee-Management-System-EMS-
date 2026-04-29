<?php
echo "Testing CSO Dashboard Endpoints...\n\n";

$endpoints = [
    'get_cso_system_logs.php' => 'System Logs',
    'get_cso_audit_results.php' => 'Audit Results',
    'get_security_alerts.php' => 'Security Alerts',
    'get_cso_vulnerability_scan_results.php' => 'Vulnerability Scans',
    'get_cso_api_monitoring_data.php' => 'API Monitoring',
    'get_cso_dashboard_data.php' => 'Dashboard Data',
    'get_attendance_system_status.php' => 'Attendance Status'
];

foreach ($endpoints as $endpoint => $name) {
    echo "Testing $name ($endpoint)...\n";
    
    // Simulate a CSO session
    session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['user_type'] = 'cso';
    $_SESSION['employee_code'] = '2025/CSO/001';
    $_SESSION['full_name'] = 'CSO Test User';
    
    // Include the endpoint file
    ob_start();
    try {
        include $endpoint;
        $output = ob_get_clean();
        
        // Check if it's valid JSON
        $data = json_decode($output, true);
        if ($data !== null) {
            if (isset($data['success']) && $data['success']) {
                echo "  ✅ Success: $name working correctly\n";
            } else {
                echo "  ⚠ Warning: $name returned error: " . ($data['message'] ?? 'Unknown error') . "\n";
            }
        } else {
            echo "  ❌ Error: $name returned invalid JSON\n";
        }
    } catch (Exception $e) {
        ob_end_clean();
        echo "  ❌ Error: $name failed with exception: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "✅ CSO endpoint testing completed!\n";
echo "If all endpoints show ✅ Success, your CSO dashboard should work perfectly.\n";
?> 