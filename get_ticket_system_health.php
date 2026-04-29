<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'employee_management_system';
$username = 'ems_user';
$password = 'securepassword123';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Function to get ticket statistics
function getTicketStatistics($pdo) {
    $stats = [];
    
    // Total tickets by status
    $statusQuery = "SELECT status, COUNT(*) as count FROM tickets GROUP BY status";
    $stmt = $pdo->query($statusQuery);
    $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($statusCounts as $status) {
        $stats[$status['status']] = (int)$status['count'];
    }
    
    // Tickets by priority
    $priorityQuery = "SELECT priority, COUNT(*) as count FROM tickets GROUP BY priority";
    $stmt = $pdo->query($priorityQuery);
    $priorityCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($priorityCounts as $priority) {
        $stats['priority_' . $priority['priority']] = (int)$priority['count'];
    }
    
    // Security tickets (CSO specific)
    $securityQuery = "SELECT COUNT(*) as count FROM tickets WHERE category = 'Security'";
    $stmt = $pdo->query($securityQuery);
    $securityCount = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['security_tickets'] = (int)$securityCount['count'];
    
    // Average resolution time
    $resolutionQuery = "
        SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours 
        FROM tickets 
        WHERE status = 'resolved' AND resolved_at IS NOT NULL
    ";
    $stmt = $pdo->query($resolutionQuery);
    $resolutionTime = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['avg_resolution_hours'] = round($resolutionTime['avg_hours'] ?? 0, 2);
    
    return $stats;
}

// Function to detect system vulnerabilities/issues
function detectVulnerabilities($pdo) {
    $vulnerabilities = [];
    
    // 1. Check for tickets stuck in "open" status for too long
    $stuckTicketsQuery = "
        SELECT id, title, priority, created_at 
        FROM tickets 
        WHERE status = 'open' 
        AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY priority DESC, created_at ASC
        LIMIT 10
    ";
    $stmt = $pdo->query($stuckTicketsQuery);
    $stuckTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($stuckTickets) > 0) {
        $vulnerabilities[] = [
            'title' => 'Stuck Tickets Detected',
            'description' => count($stuckTickets) . ' tickets have been open for more than 7 days',
            'severity' => 'high',
            'date_found' => date('Y-m-d H:i:s'),
            'details' => $stuckTickets
        ];
    }
    
    // 2. Check for high-priority tickets not assigned
    $unassignedHighQuery = "
        SELECT COUNT(*) as count 
        FROM tickets 
        WHERE priority IN ('high', 'critical') 
        AND status = 'open'
        AND assigned_to IS NULL
    ";
    $stmt = $pdo->query($unassignedHighQuery);
    $unassignedHigh = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($unassignedHigh['count'] > 0) {
        $vulnerabilities[] = [
            'title' => 'Unassigned High-Priority Tickets',
            'description' => $unassignedHigh['count'] . ' high/critical priority tickets are not assigned',
            'severity' => 'critical',
            'date_found' => date('Y-m-d H:i:s')
        ];
    }
    
    // 3. Check for security tickets without CSO assignment
    $unassignedSecurityQuery = "
        SELECT COUNT(*) as count 
        FROM tickets 
        WHERE category = 'Security' 
        AND (assigned_to IS NULL OR assigned_to != 'cso')
    ";
    $stmt = $pdo->query($unassignedSecurityQuery);
    $unassignedSecurity = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($unassignedSecurity['count'] > 0) {
        $vulnerabilities[] = [
            'title' => 'Security Tickets Not Assigned to CSO',
            'description' => $unassignedSecurity['count'] . ' security tickets are not assigned to CSO',
            'severity' => 'critical',
            'date_found' => date('Y-m-d H:i:s')
        ];
    }
    
    // 4. Check for system performance issues (too many open tickets)
    $openTicketsQuery = "SELECT COUNT(*) as count FROM tickets WHERE status = 'open'";
    $stmt = $pdo->query($openTicketsQuery);
    $openTickets = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($openTickets['count'] > 50) {
        $vulnerabilities[] = [
            'title' => 'High Volume of Open Tickets',
            'description' => $openTickets['count'] . ' open tickets may indicate system overload',
            'severity' => 'warning',
            'date_found' => date('Y-m-d H:i:s')
        ];
    }
    
    return $vulnerabilities;
}

// Function to get performance messages
function getPerformanceMessages($pdo) {
    $messages = [];
    
    // Database connection health
    try {
        $pdo->query("SELECT 1");
        $messages[] = [
            'type' => 'info',
            'title' => 'Database Connection',
            'message' => 'Database connection is healthy',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    } catch (Exception $e) {
        $messages[] = [
            'type' => 'error',
            'title' => 'Database Connection Error',
            'message' => 'Unable to connect to database: ' . $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    // Ticket processing rate (last 24 hours)
    $processingQuery = "
        SELECT 
            COUNT(*) as tickets_created,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as tickets_resolved
        FROM tickets 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ";
    $stmt = $pdo->query($processingQuery);
    $processingStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $completionRate = $processingStats['tickets_created'] > 0 ? 
        ($processingStats['tickets_resolved'] / $processingStats['tickets_created']) * 100 : 0;
    
    if ($completionRate < 50) {
        $messages[] = [
            'type' => 'warning',
            'title' => 'Low Ticket Completion Rate',
            'message' => 'Only ' . round($completionRate, 1) . '% of tickets created in last 24 hours were resolved',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    } else {
        $messages[] = [
            'type' => 'info',
            'title' => 'Ticket Processing',
            'message' => round($completionRate, 1) . '% of recent tickets resolved',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    // System load based on ticket categories
    $categoryLoadQuery = "
        SELECT category, COUNT(*) as count 
        FROM tickets 
        WHERE status = 'open' 
        GROUP BY category 
        ORDER BY count DESC 
        LIMIT 5
    ";
    $stmt = $pdo->query($categoryLoadQuery);
    $categoryLoad = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($categoryLoad) > 0) {
        $topCategory = $categoryLoad[0];
        if ($topCategory['count'] > 10) {
            $messages[] = [
                'type' => 'info',
                'title' => 'Category Load',
                'message' => $topCategory['category'] . ' has ' . $topCategory['count'] . ' open tickets (highest load)',
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    return $messages;
}

// Function to determine overall system health status
function getSystemHealthStatus($pdo, $vulnerabilities) {
    $criticalCount = 0;
    $warningCount = 0;
    
    foreach ($vulnerabilities as $vuln) {
        if ($vuln['severity'] === 'critical') $criticalCount++;
        if ($vuln['severity'] === 'warning') $warningCount++;
    }
    
    if ($criticalCount > 0) {
        $status = 'critical';
    } elseif ($warningCount > 0) {
        $status = 'warning';
    } else {
        $status = 'good';
    }
    
    // Get component statuses
    $components = [];
    
    // Database component
    try {
        $pdo->query("SELECT 1");
        $components[] = ['name' => 'Database', 'status' => 'good'];
    } catch (Exception $e) {
        $components[] = ['name' => 'Database', 'status' => 'critical'];
    }
    
    // Ticket processing component
    $openTicketsQuery = "SELECT COUNT(*) as count FROM tickets WHERE status = 'open'";
    $stmt = $pdo->query($openTicketsQuery);
    $openTickets = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $ticketStatus = $openTickets['count'] > 100 ? 'warning' : 'good';
    $components[] = ['name' => 'Ticket Processing', 'status' => $ticketStatus];
    
    // Security component (CSO assignment)
    $securityQuery = "SELECT COUNT(*) as count FROM tickets WHERE category = 'Security' AND assigned_to != 'cso'";
    $stmt = $pdo->query($securityQuery);
    $securityIssues = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $securityStatus = $securityIssues['count'] > 0 ? 'critical' : 'good';
    $components[] = ['name' => 'Security Assignment', 'status' => $securityStatus];
    
    return [
        'status' => $status,
        'last_checked' => date('Y-m-d H:i:s'),
        'components' => $components
    ];
}

// Main execution
try {
    // Collect all data
    $ticketStatistics = getTicketStatistics($pdo);
    $vulnerabilities = detectVulnerabilities($pdo);
    $performanceMessages = getPerformanceMessages($pdo);
    $healthStatus = getSystemHealthStatus($pdo, $vulnerabilities);
    
    // Prepare response
    $response = [
        'success' => true,
        'health_status' => $healthStatus,
        'ticket_statistics' => $ticketStatistics,
        'vulnerabilities' => $vulnerabilities,
        'performance_messages' => $performanceMessages,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error generating system health report: ' . $e->getMessage()
    ]);
}
?>