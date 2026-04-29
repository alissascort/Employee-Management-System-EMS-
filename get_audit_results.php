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
    
    // Get filter parameters
    $search = $_GET['search'] ?? '';
    $type = $_GET['type'] ?? 'all';
    $date = $_GET['date'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    // Build WHERE clause
    $whereConditions = [];
    $params = [];
    
    if (!empty($search)) {
        $whereConditions[] = "(a.title LIKE ? OR a.summary LIKE ? OR af.description LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if (!empty($date)) {
        $whereConditions[] = "a.date = ?";
        $params[] = $date;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(DISTINCT a.id) as total
        FROM audits a
        LEFT JOIN audit_findings af ON a.id = af.audit_id
        $whereClause
    ";
    
    $stmt = $conn->prepare($countQuery);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get audit results with findings
    $query = "
        SELECT 
            a.id,
            a.title,
            a.date,
            a.conducted_by,
            a.summary,
            COUNT(af.id) as issues_found,
            SUM(CASE WHEN af.severity = 'critical' THEN 1 ELSE 0 END) as critical_count,
            SUM(CASE WHEN af.severity = 'high' THEN 1 ELSE 0 END) as high_count,
            SUM(CASE WHEN af.severity = 'medium' THEN 1 ELSE 0 END) as medium_count,
            SUM(CASE WHEN af.severity = 'low' THEN 1 ELSE 0 END) as low_count,
            SUM(CASE WHEN af.status = 'resolved' THEN 1 ELSE 0 END) as resolved_count
        FROM audits a
        LEFT JOIN audit_findings af ON a.id = af.audit_id
        $whereClause
        GROUP BY a.id, a.title, a.date, a.conducted_by, a.summary
        ORDER BY a.date DESC, a.id DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $audits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get summary statistics
    $statsQuery = "
        SELECT 
            COUNT(DISTINCT a.id) as total_audits,
            SUM(CASE WHEN af.severity = 'critical' THEN 1 ELSE 0 END) as critical_issues,
            SUM(CASE WHEN af.severity = 'high' THEN 1 ELSE 0 END) as warning_issues,
            SUM(CASE WHEN af.status = 'resolved' THEN 1 ELSE 0 END) as resolved_issues
        FROM audits a
        LEFT JOIN audit_findings af ON a.id = af.audit_id
    ";
    
    $stmt = $conn->prepare($statsQuery);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate total pages
    $totalPages = ceil($totalCount / $limit);
    
    echo json_encode([
        'success' => true,
        'audits' => $audits,
        'stats' => [
            'completed_audits' => intval($stats['total_audits']),
            'critical_issues' => intval($stats['critical_issues']),
            'warning_issues' => intval($stats['warning_issues']),
            'resolved_issues' => intval($stats['resolved_issues'])
        ],
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
            'per_page' => $limit
        ],
        'last_updated' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Audit results error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load audit results: ' . $e->getMessage()
    ]);
}
?>
