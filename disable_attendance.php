<?php
session_start();
header('Content-Type: application/json');

// Security checks - only CSO can access this
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. CSO role required.']);
    exit;
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? '';
$reason = $input['reason'] ?? $_POST['reason'] ?? 'Terminal command';

if (empty($action)) {
    echo json_encode(['success' => false, 'message' => 'No action specified.']);
    exit;
}

// Log the action
logAttendanceAction($action, $reason, $_SESSION['user_id'], $_SESSION['full_name'] ?? 'Unknown CSO');

switch ($action) {
    case 'disable_system':
        $result = disableAttendanceSystem($reason);
        break;
    case 'enable_system':
        $result = enableAttendanceSystem();
        break;
    case 'get_status':
        $result = getAttendanceSystemStatus();
        break;
    case 'send_notification':
        $message = $input['message'] ?? $_POST['message'] ?? '';
        $result = sendSystemNotification($message);
        break;
    default:
        $result = ['success' => false, 'message' => 'Unknown action: ' . $action];
        break;
}

echo json_encode($result);

function disableAttendanceSystem($reason) {
    // Create a system lock file to indicate attendance is disabled
    $lockFile = 'attendance_system.lock';
    $lockData = [
        'disabled' => true,
        'disabled_at' => date('Y-m-d H:i:s'),
        'disabled_by' => $_SESSION['full_name'] ?? 'Unknown CSO',
        'disabled_by_id' => $_SESSION['user_id'],
        'reason' => $reason,
        'ip_address' => $_SERVER['REMOTE_ADDR']
    ];
    
    if (file_put_contents($lockFile, json_encode($lockData)) {
        // Log the action to system logs
        $logMessage = "ATTENDANCE SYSTEM DISABLED by {$_SESSION['full_name']} - Reason: $reason";
        systemLog('SECURITY', $logMessage);
        
        // Notify active users (in a real system, this would send actual notifications)
        notifyActiveUsers("System Alert: Digital attendance system has been disabled. Reason: $reason");
        
        return [
            'success' => true,
            'message' => 'Attendance system disabled successfully',
            'disabled_at' => $lockData['disabled_at'],
            'disabled_by' => $lockData['disabled_by'],
            'reason' => $reason
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Failed to disable attendance system'
        ];
    }
}

function enableAttendanceSystem() {
    $lockFile = 'attendance_system.lock';
    
    if (file_exists($lockFile)) {
        // Read the lock file to get disable info
        $lockData = json_decode(file_get_contents($lockFile), true);
        $lockData['enabled_at'] = date('Y-m-d H:i:s');
        $lockData['enabled_by'] = $_SESSION['full_name'] ?? 'Unknown CSO';
        $lockData['enabled_by_id'] = $_SESSION['user_id'];
        
        // Archive the lock file instead of deleting (for audit purposes)
        $archiveFile = 'attendance_system_archive_' . date('Ymd_His') . '.lock';
        file_put_contents($archiveFile, json_encode($lockData));
        
        // Remove the active lock file
        unlink($lockFile);
        
        // Log the action
        $logMessage = "ATTENDANCE SYSTEM ENABLED by {$_SESSION['full_name']}";
        systemLog('SECURITY', $logMessage);
        
        // Notify active users
        notifyActiveUsers("System Alert: Digital attendance system has been re-enabled and is now operational.");
        
        return [
            'success' => true,
            'message' => 'Attendance system enabled successfully',
            'enabled_at' => $lockData['enabled_at'],
            'enabled_by' => $lockData['enabled_by'],
            'previously_disabled_at' => $lockData['disabled_at'],
            'previous_reason' => $lockData['reason'] ?? 'Unknown'
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Attendance system is not currently disabled'
        ];
    }
}

function getAttendanceSystemStatus() {
    $lockFile = 'attendance_system.lock';
    $statusFile = 'attendance_system_status.json';
    
    $status = [
        'system_operational' => !file_exists($lockFile),
        'last_checked' => date('Y-m-d H:i:s'),
        'active_users' => getActiveUserCount(),
        'recent_activity' => getRecentAttendanceActivity()
    ];
    
    if (file_exists($lockFile)) {
        $lockData = json_decode(file_get_contents($lockFile), true);
        $status['disabled_info'] = $lockData;
    }
    
    // Save status for dashboard display
    file_put_contents($statusFile, json_encode($status));
    
    return [
        'success' => true,
        'status' => $status
    ];
}

function sendSystemNotification($message) {
    if (empty($message)) {
        return [
            'success' => false,
            'message' => 'No notification message provided'
        ];
    }
    
    // Log the notification
    $logMessage = "SYSTEM NOTIFICATION by {$_SESSION['full_name']}: $message";
    systemLog('NOTIFICATION', $logMessage);
    
    // In a real system, this would:
    // 1. Send to all active users via WebSocket
    // 2. Send email notifications
    // 3. Send mobile push notifications
    // 4. Log to database
    
    // For now, we'll create a notification file
    $notification = [
        'timestamp' => date('Y-m-d H:i:s'),
        'from' => $_SESSION['full_name'] ?? 'CSO Terminal',
        'message' => $message,
        'priority' => 'high',
        'acknowledged_by' => []
    ];
    
    $notificationFile = 'system_notifications.json';
    $notifications = [];
    
    if (file_exists($notificationFile)) {
        $notifications = json_decode(file_get_contents($notificationFile), true);
    }
    
    $notifications[] = $notification;
    file_put_contents($notificationFile, json_encode($notifications));
    
    return [
        'success' => true,
        'message' => 'System notification sent successfully',
        'notification' => $notification
    ];
}

function logAttendanceAction($action, $reason, $userId, $userName) {
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $userId,
        'user_name' => $userName,
        'action' => $action,
        'reason' => $reason,
        'ip_address' => $_SERVER['REMOTE_ADDR']
    ];
    
    // Log to audit file
    file_put_contents('attendance_actions.log', json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
    
    // Also log to main system logs
    systemLog('AUDIT', "Attendance action: $action by $userName - Reason: $reason");
}

function systemLog($level, $message) {
    $logEntry = date('Y-m-d H:i:s') . " [$level] $message\n";
    file_put_contents('system_security.log', $logEntry, FILE_APPEND | LOCK_EX);
    
    // Also log to main system logs if exists
    if (file_exists('system_logs.log')) {
        file_put_contents('system_logs.log', $logEntry, FILE_APPEND | LOCK_EX);
    }
}

function notifyActiveUsers($message) {
    // In a real system, this would use WebSocket or push notifications
    // For now, we'll create a notification file that the frontend can poll
    $notification = [
        'id' => uniqid(),
        'timestamp' => date('Y-m-d H:i:s'),
        'type' => 'system_alert',
        'message' => $message,
        'priority' => 'high'
    ];
    
    $activeNotificationsFile = 'active_notifications.json';
    $notifications = [];
    
    if (file_exists($activeNotificationsFile)) {
        $notifications = json_decode(file_get_contents($activeNotificationsFile), true);
    }
    
    $notifications[] = $notification;
    file_put_contents($activeNotificationsFile, json_encode($notifications));
}

function getActiveUserCount() {
    // This would query your session database
    // For now, return a simulated count
    return rand(5, 25);
}

function getRecentAttendanceActivity() {
    // This would query recent attendance records
    // For now, return simulated data
    return [
        'check_ins_last_hour' => rand(10, 50),
        'check_outs_last_hour' => rand(8, 45),
        'active_sessions' => rand(15, 40),
        'last_activity' => date('Y-m-d H:i:s', strtotime('-5 minutes'))
    ];
}

// Handle direct POST requests (for the System Monitor dashboard)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $reason = $_POST['reason'] ?? 'Dashboard control';
    
    switch ($action) {
        case 'disable_system':
            $result = disableAttendanceSystem($reason);
            break;
        case 'enable_system':
            $result = enableAttendanceSystem();
            break;
        case 'send_notification':
            $message = $_POST['message'] ?? '';
            $result = sendSystemNotification($message);
            break;
        default:
            $result = ['success' => false, 'message' => 'Unknown action'];
            break;
    }
    
    echo json_encode($result);
    exit;
}
?>