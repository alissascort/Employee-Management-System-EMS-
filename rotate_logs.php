<?php
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// rotate_logs.php
function rotateSystemLogs($daysToKeep = 90) {
    global $db;
    
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-$daysToKeep days"));
    
    try {
        $stmt = $db->prepare("DELETE FROM system_logs WHERE timestamp < ?");
        $stmt->execute([$cutoffDate]);
        
        $deletedCount = $stmt->rowCount();
        logSystemActivity('INFO', 'log_rotation', "Rotated system logs. Removed $deletedCount entries older than $cutoffDate");
        
        return $deletedCount;
    } catch (PDOException $e) {
        error_log("Log rotation failed: " . $e->getMessage());
        return false;
    }
}
?>