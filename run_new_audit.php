<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Check if user is authenticated as CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Start transaction
    $conn->beginTransaction();
    
    // Create new audit record
    $auditTitle = "Automated Security Audit - " . date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        INSERT INTO audits (title, date, conducted_by, summary) 
        VALUES (?, CURDATE(), ?, ?)
    ");
    
    $summary = "Automated security audit conducted by CSO system. Comprehensive analysis of system security, user access, and potential vulnerabilities.";
    
    $stmt->execute([$auditTitle, $_SESSION['user_id'], $summary]);
    $auditId = $conn->lastInsertId();
    
    // Run automated security checks
    $findings = [];
    
    // 1. Check for weak passwords
    $weakPasswordCount = checkWeakPasswords($conn);
    if ($weakPasswordCount > 0) {
        $findings[] = [
            'description' => "Found $weakPasswordCount user(s) with potentially weak passwords",
            'severity' => 'high',
            'recommendation' => 'Enforce password complexity requirements and require password changes',
            'status' => 'open'
        ];
    }
    
    // 2. Check for inactive sessions
    $inactiveSessionCount = checkInactiveSessions($conn);
    if ($inactiveSessionCount > 0) {
        $findings[] = [
            'description' => "Found $inactiveSessionCount inactive user session(s) that should be cleaned up",
            'severity' => 'medium',
            'recommendation' => 'Implement automatic session cleanup and enforce session timeouts',
            'status' => 'open'
        ];
    }
    
    // 3. Check for failed login attempts
    $failedLoginCount = checkFailedLogins($conn);
    if ($failedLoginCount > 5) {
        $findings[] = [
            'description' => "High number of failed login attempts detected ($failedLoginCount in last 24 hours)",
            'severity' => 'critical',
            'recommendation' => 'Investigate potential brute force attacks and implement account lockout policies',
            'status' => 'open'
        ];
    }
    
    // 4. Check for system performance issues
    $performanceIssues = checkSystemPerformance($conn);
    if (!empty($performanceIssues)) {
        foreach ($performanceIssues as $issue) {
            $findings[] = $issue;
        }
    }
    
    // 5. Check for security incidents
    $securityIncidentCount = checkSecurityIncidents($conn);
    if ($securityIncidentCount > 0) {
        $findings[] = [
            'description' => "Found $securityIncidentCount active security incident(s) requiring attention",
            'severity' => 'critical',
            'recommendation' => 'Review and resolve all active security incidents immediately',
            'status' => 'open'
        ];
    }
    
    // 6. Check for outdated system components (only report if actually outdated)
    $outdatedComponents = checkOutdatedComponents();
    if (!empty($outdatedComponents)) {
        $findings[] = [
            'description' => "System components may need updates: " . implode(', ', $outdatedComponents),
            'severity' => 'medium',
            'recommendation' => 'Schedule system updates and security patches',
            'status' => 'open'
        ];
    }
    
    // Only add findings if there are actual issues detected
    // No dummy "all clear" findings - let the system show real status
    
    // Insert findings into database
    $stmt = $conn->prepare("
        INSERT INTO audit_findings (audit_id, description, severity, recommendation, status) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    foreach ($findings as $finding) {
        $stmt->execute([
            $auditId,
            $finding['description'],
            $finding['severity'],
            $finding['recommendation'],
            $finding['status']
        ]);
    }
    
    // Log the audit action
    $stmt = $conn->prepare("
        INSERT INTO login_logs (user_id, user_type, action, ip_address, status) 
        VALUES (?, 'cso', 'security_audit_conducted', ?, 'success')
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Security audit completed successfully',
        'audit_id' => $auditId,
        'findings_count' => count($findings),
        'critical_count' => count(array_filter($findings, fn($f) => $f['severity'] === 'critical')),
        'high_count' => count(array_filter($findings, fn($f) => $f['severity'] === 'high'))
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollback();
    }
    
    error_log("New audit error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to run new audit: ' . $e->getMessage()
    ]);
}

// Helper functions for security checks
function checkWeakPasswords($conn) {
    try {
        // Check for users with simple passwords (this is a simplified check)
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM employees 
            WHERE password_hash IS NULL OR password_hash = ''
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    } catch (Exception $e) {
        error_log("Weak password check error: " . $e->getMessage());
        return 0;
    }
}

function checkInactiveSessions($conn) {
    try {
        // Check for sessions older than 24 hours
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM user_sessions 
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    } catch (Exception $e) {
        error_log("Inactive sessions check error: " . $e->getMessage());
        return 0;
    }
}

function checkFailedLogins($conn) {
    try {
        // Check for failed login attempts in last 24 hours
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM login_attempts 
            WHERE success = 0 
            AND attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    } catch (Exception $e) {
        error_log("Failed logins check error: " . $e->getMessage());
        return 0;
    }
}

function checkSystemPerformance($conn) {
    $issues = [];
    try {
        // Check for high system load
        $load = sys_getloadavg();
        if ($load[0] > 5.0) {
            $issues[] = [
                'description' => "High system load detected: " . round($load[0], 2),
                'severity' => 'high',
                'recommendation' => 'Investigate system performance and resource usage',
                'status' => 'open'
            ];
        }
        
        // Check for memory issues
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = return_bytes($memoryLimit);
        
        if ($memoryUsage > ($memoryLimitBytes * 0.8)) {
            $issues[] = [
                'description' => "High memory usage detected: " . round(($memoryUsage / $memoryLimitBytes) * 100, 1) . "%",
                'severity' => 'medium',
                'recommendation' => 'Optimize memory usage and consider increasing memory limit',
                'status' => 'open'
            ];
        }
        
    } catch (Exception $e) {
        error_log("System performance check error: " . $e->getMessage());
    }
    
    return $issues;
}

function checkSecurityIncidents($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM security_incidents 
            WHERE status = 'active' OR status = 'open'
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    } catch (Exception $e) {
        error_log("Security incidents check error: " . $e->getMessage());
        return 0;
    }
}

function checkOutdatedComponents() {
    $outdated = [];
    
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.0.0', '<')) {
        $outdated[] = 'PHP (' . PHP_VERSION . ')';
    }
    
    // Check for common security headers
    $headers = headers_list();
    $securityHeaders = ['X-Frame-Options', 'X-Content-Type-Options', 'X-XSS-Protection'];
    foreach ($securityHeaders as $header) {
        if (!array_filter($headers, fn($h) => stripos($h, $header) !== false)) {
            $outdated[] = "Missing $header header";
        }
    }
    
    return $outdated;
}

function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    switch($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}
?>
