<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

class AuditManager {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function getAuditLogs($filters = [], $page = 1, $limit = 20) {
        try {
            $whereConditions = [];
            $params = [];
            
            // Build filter conditions
            if (!empty($filters['action_type'])) {
                $whereConditions[] = "al.action_type = ?";
                $params[] = $filters['action_type'];
            }
            
            if (!empty($filters['employee_id'])) {
                $whereConditions[] = "al.employee_id = ?";
                $params[] = $filters['employee_id'];
            }
            
            if (!empty($filters['module'])) {
                $whereConditions[] = "al.module = ?";
                $params[] = $filters['module'];
            }
            
            if (!empty($filters['date_from'])) {
                $whereConditions[] = "al.action_date >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = "al.action_date <= ?";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['search'])) {
                $whereConditions[] = "(al.action_details LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)";
                $searchTerm = "%{$filters['search']}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (!empty($filters['ip_address'])) {
                $whereConditions[] = "al.ip_address LIKE ?";
                $params[] = "%{$filters['ip_address']}%";
            }
            
            $whereClause = "";
            if (!empty($whereConditions)) {
                $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            }
            
            // Count total records
            $countQuery = "SELECT COUNT(*) as total 
                          FROM audit_logs al
                          LEFT JOIN staff_profiles u ON al.employee_id = u.employee_id
                          {$whereClause}";
            
            $stmt = $this->conn->prepare($countQuery);
            $stmt->execute($params);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Main query
            $offset = ($page - 1) * $limit;
            $query = "SELECT 
                        al.log_id,
                        al.action_type,
                        al.module,
                        al.action_details,
                        al.action_date,
                        al.ip_address,
                        al.employee_agent,
                        al.record_id,
                        al.old_values,
                        al.new_values,
                        al.status,
                        al.duration_ms,
                        u.employee_id,
                        u.employee_code,
                        u.full_name,
                        u.department_id,
                        d.department_name
                    FROM audit_logs al
                    LEFT JOIN staff_profiles u ON al.employee_id = u.employee_id
                    LEFT JOIN departments d ON u.department_id = d.department_id
                    {$whereClause}
                    ORDER BY al.action_date DESC
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format data
            foreach ($logs as &$log) {
                $log['action_date_formatted'] = date('M j, Y g:i A', strtotime($log['action_date']));
                $log['duration_formatted'] = $log['duration_ms'] ? $log['duration_ms'] . 'ms' : 'N/A';
                
                // Parse JSON values if they exist
                if ($log['old_values']) {
                    $log['old_values_parsed'] = json_decode($log['old_values'], true);
                }
                if ($log['new_values']) {
                    $log['new_values_parsed'] = json_decode($log['new_values'], true);
                }
            }
            
            // Get audit statistics
            $statistics = $this->getAuditStatistics();
            
            return [
                'success' => true,
                'data' => $logs,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => ceil($total / $limit),
                    'total_records' => $total,
                    'limit' => $limit
                ],
                'statistics' => $statistics
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function getAuditStatistics() {
        // Action type distribution
        $actionQuery = "SELECT action_type, COUNT(*) as count 
                       FROM audit_logs 
                       WHERE action_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                       GROUP BY action_type 
                       ORDER BY count DESC";
        $stmt = $this->conn->prepare($actionQuery);
        $stmt->execute();
        $actionStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Module distribution
        $moduleQuery = "SELECT module, COUNT(*) as count 
                       FROM audit_logs 
                       WHERE action_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                       GROUP BY module 
                       ORDER BY count DESC";
        $stmt = $this->conn->prepare($moduleQuery);
        $stmt->execute();
        $moduleStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Daily activity
        $dailyQuery = "SELECT 
                      DATE(action_date) as date,
                      COUNT(*) as activity_count
                      FROM audit_logs
                      WHERE action_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                      GROUP BY DATE(action_date)
                      ORDER BY date DESC";
        $stmt = $this->conn->prepare($dailyQuery);
        $stmt->execute();
        $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top users
        $userQuery = "SELECT 
                     u.full_name,
                     COUNT(al.log_id) as activity_count
                     FROM audit_logs al
                     JOIN staff_profiles u ON al.employee_id = u.employee_id
                     WHERE al.action_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                     GROUP BY u.employee_id, u.full_name
                     ORDER BY activity_count DESC
                     LIMIT 10";
        $stmt = $this->conn->prepare($userQuery);
        $stmt->execute();
        $userStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // System performance
        $performanceQuery = "SELECT 
                           AVG(duration_ms) as avg_response_time,
                           MAX(duration_ms) as max_response_time,
                           COUNT(*) as total_actions
                           FROM audit_logs
                           WHERE action_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
        $stmt = $this->conn->prepare($performanceQuery);
        $stmt->execute();
        $performanceStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'action_distribution' => $actionStats,
            'module_distribution' => $moduleStats,
            'daily_activity' => $dailyStats,
            'top_users' => $userStats,
            'performance' => $performanceStats
        ];
    }
    
    public function logAction($actionData) {
        try {
            $query = "INSERT INTO audit_logs 
                     (employee_id, action_type, module, action_details, action_date, ip_address, 
                      employee_agent, record_id, old_values, new_values, status, duration_ms)
                     VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $success = $stmt->execute([
                $actionData['employee_id'] ?? null,
                $actionData['action_type'],
                $actionData['module'],
                $actionData['action_details'],
                $this->getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $actionData['record_id'] ?? null,
                $actionData['old_values'] ?? null,
                $actionData['new_values'] ?? null,
                $actionData['status'] ?? 'success',
                $actionData['duration_ms'] ?? null
            ]);
            
            return $success;
            
        } catch (Exception $e) {
            error_log("Audit log error: " . $e->getMessage());
            return false;
        }
    }
    
    public function getSecurityAlerts() {
        try {
            // Detect suspicious activities
            $suspiciousQuery = "SELECT 
                               al.*, u.full_name, u.username
                               FROM audit_logs al
                               JOIN staff_profiles u ON al.employee_id = u.employee_id
                               WHERE (
                                 al.action_type IN ('LOGIN_FAILED', 'UNAUTHORIZED_ACCESS') OR
                                 al.module = 'SECURITY' OR
                                 al.ip_address IN (
                                   SELECT ip_address FROM audit_logs 
                                   WHERE action_type = 'LOGIN_FAILED' 
                                   GROUP BY ip_address 
                                   HAVING COUNT(*) > 5
                                 )
                               )
                               AND al.action_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                               ORDER BY al.action_date DESC
                               LIMIT 50";
            
            $stmt = $this->conn->prepare($suspiciousQuery);
            $stmt->execute();
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $alerts;
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    private function getClientIP() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        
        return $ip;
    }
}

// Process request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $database = new Database();
    $db = $database->getConnection();
    $auditManager = new AuditManager($db);
    
    $filters = [
        'action_type' => $_GET['action_type'] ?? null,
        'employee_id' => $_GET['employee_id'] ?? null,
        'module' => $_GET['module'] ?? null,
        'date_from' => $_GET['date_from'] ?? null,
        'date_to' => $_GET['date_to'] ?? null,
        'search' => $_GET['search'] ?? null,
        'ip_address' => $_GET['ip_address'] ?? null
    ];
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    
    $result = $auditManager->getAuditLogs($filters, $page, $limit);
    
    // Include security alerts for HR dashboard
    if ($page === 1) {
        $result['security_alerts'] = $auditManager->getSecurityAlerts();
    }
    
    echo json_encode($result);
}
?>