<?php
/**
 * Terminal Audit Module
 * Handles immutable audit logging with cryptographic verification
 */
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

class TerminalAudit {
    private $conn;
    private $auditLogFile = '/secure/logs/terminal_audit.log';
    
    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
        
        // Ensure log directory exists
        if (!is_dir(dirname($this->auditLogFile))) {
            mkdir(dirname($this->auditLogFile), 0750, true);
        }
    }
    
    public function log($eventType, $userId, $action, $details, $status = 'success') {
        $entry = [
            'id' => uniqid('audit_', true),
            'timestamp' => date('Y-m-d H:i:s'),
            'event_type' => $eventType,
            'user_id' => $userId,
            'user_name' => $_SESSION['full_name'] ?? 'Unknown',
            'action' => $action,
            'details' => $details,
            'status' => $status,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'session_id' => session_id(),
            'signature' => ''
        ];
        
        // Generate signature for integrity
        $signatureData = $entry['id'] . $entry['timestamp'] . $entry['user_id'] . $entry['action'];
        $entry['signature'] = hash_hmac('sha256', $signatureData, $this->getAuditKey());
        
        // Write to database
        $this->logToDatabase($entry);
        
        // Write to immutable file
        $this->logToFile($entry);
        
        return $entry['id'];
    }
    
    private function logToDatabase($entry) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO terminal_audit_logs 
                (audit_id, event_type, user_id, user_name, action, details, status, ip_address, session_id, signature, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $entry['id'],
                $entry['event_type'],
                $entry['user_id'],
                $entry['user_name'],
                $entry['action'],
                $entry['details'],
                $entry['status'],
                $entry['ip_address'],
                $entry['session_id'],
                $entry['signature']
            ]);
        } catch (Exception $e) {
            error_log("Audit database log failed: " . $e->getMessage());
        }
    }
    
    private function logToFile($entry) {
        $logLine = json_encode($entry) . "\n";
        
        // Write with exclusive lock
        $fp = fopen($this->auditLogFile, 'a');
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $logLine);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    
    private function getAuditKey() {
        $keyFile = '/secure/keys/audit.key';
        if (file_exists($keyFile)) {
            return file_get_contents($keyFile);
        }
        
        $key = random_bytes(32);
        if (!is_dir('/secure/keys')) {
            mkdir('/secure/keys', 0700, true);
        }
        file_put_contents($keyFile, $key, LOCK_EX);
        chmod($keyFile, 0600);
        return $key;
    }
    
    public function verifyAuditIntegrity($auditId) {
        $stmt = $this->conn->prepare("
            SELECT * FROM terminal_audit_logs WHERE audit_id = ?
        ");
        $stmt->execute([$auditId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record) return false;
        
        $signatureData = $record['audit_id'] . $record['created_at'] . $record['user_id'] . $record['action'];
        $expectedSignature = hash_hmac('sha256', $signatureData, $this->getAuditKey());
        
        return hash_equals($expectedSignature, $record['signature']);
    }
    
    public function getCommandHistory($userId = null, $limit = 50) {
        $sql = "
            SELECT * FROM terminal_audit_logs 
            WHERE event_type = 'command'
        ";
        $params = [];
        
        if ($userId) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function exportAuditLogs($startDate, $endDate, $format = 'json') {
        $stmt = $this->conn->prepare("
            SELECT * FROM terminal_audit_logs 
            WHERE created_at BETWEEN ? AND ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($format === 'json') {
            return json_encode($logs, JSON_PRETTY_PRINT);
        }
        
        // CSV format
        $output = "Audit ID,Timestamp,Event Type,User,Action,Details,Status,IP Address\n";
        foreach ($logs as $log) {
            $output .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s\n",
                $log['audit_id'],
                $log['created_at'],
                $log['event_type'],
                $log['user_name'],
                $log['action'],
                str_replace(',', ';', $log['details']),
                $log['status'],
                $log['ip_address']
            );
        }
        
        return $output;
    }
}
?>
