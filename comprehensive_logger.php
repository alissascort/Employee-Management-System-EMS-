<?php
// comprehensive_logger.php
function logSystemActivity($level, $type, $message, $userId = null, $ipAddress = null) {
    global $db;
    
    if ($ipAddress === null) {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO system_logs 
                            (log_level, log_type, type, message, timestamp, ip_address, user_id) 
                            VALUES (?, ?, ?, ?, NOW(), ?, ?)");
        
        // Map the type to appropriate categories
        $logType = mapLogType($type);
        
        $stmt->execute([$level, $logType, $type, $message, $ipAddress, $userId]);
        return true;
    } catch (PDOException $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}

function mapLogType($activityType) {
    $typeMapping = [
        // Authentication events
        'login_success' => 'authentication',
        'login_failed' => 'authentication',
        'logout' => 'authentication',
        'password_change' => 'authentication',
        'password_reset' => 'authentication',
        
        // Security events
        'unauthorized_access' => 'security',
        'brute_force_attempt' => 'security',
        'suspicious_activity' => 'security',
        'file_upload' => 'security',
        'data_export' => 'security',
        
        // System operations
        'system_startup' => 'system',
        'system_shutdown' => 'system',
        'backup_created' => 'system',
        'maintenance_mode' => 'system',
        
        // Database operations
        'db_connection' => 'database',
        'db_error' => 'database',
        'db_backup' => 'database',
        
        // User management
        'user_created' => 'user_management',
        'user_updated' => 'user_management',
        'user_deleted' => 'user_management',
        
        // CSO specific activities
        'cso_checkin' => 'cso_attendance',
        'cso_checkout' => 'cso_attendance',
        'patrol_start' => 'cso_operations',
        'patrol_end' => 'cso_operations',
        'incident_report' => 'cso_operations'
    ];
    
    return $typeMapping[$activityType] ?? 'general';
}

// Usage examples throughout your system:
// logSystemActivity('INFO', 'login_success', 'User john_doe logged in successfully', 123, '192.168.1.100');
// logSystemActivity('WARNING', 'brute_force_attempt', 'Multiple failed login attempts from IP 192.168.1.50', null, '192.168.1.50');
// logSystemActivity('ERROR', 'db_connection', 'Database connection timeout', null, 'localhost');
?>