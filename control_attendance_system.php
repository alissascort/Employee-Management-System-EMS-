<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

session_start();

// Check if user is logged in and is CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    error_log("CSO Auth Failed - user_id: " . ($_SESSION['user_id'] ?? 'not set') . ", user_type: " . ($_SESSION['user_type'] ?? 'not set'));
    echo json_encode(['success' => false, 'message' => 'Unauthorized access - Session: ' . json_encode($_SESSION)]);
    exit;
}

$action = $_POST['action'] ?? '';
$reason = $_POST['reason'] ?? '';
$notification_message = $_POST['message'] ?? '';

try {
    $db = new Database();
    $conn = $db->connect();
    
    $current_time = date('Y-m-d H:i:s');
    $cso_id = $_SESSION['user_id'];
    
    switch ($action) {
        case 'disable_system':
            // Disable digital attendance system
            $stmt = $conn->prepare("
                INSERT INTO system_settings (setting_key, value, updated_by, updated_at) 
                VALUES ('digital_attendance_enabled', '0', ?, ?)
                ON DUPLICATE KEY UPDATE 
                value = '0', updated_by = ?, updated_at = ?
            ");
            $stmt->execute([$cso_id, $current_time, $cso_id, $current_time]);
            
            // Log the action
            $stmt = $conn->prepare("
                INSERT INTO security_alerts (severity, alert_type, description, user_id, created_at, status)
                VALUES ('critical', 'system_control', 'Digital attendance system disabled by CSO. Reason: ' . ?, ?, ?, 'active')
            ");
            $stmt->execute([$reason, $cso_id, $current_time]);
            
            // Send notification to all users
            $notification = "🚨 ATTENTION: Digital attendance system has been DISABLED by Security Officer. Please use manual attendance until further notice. Reason: " . $reason;
            sendSystemNotification($conn, $notification, 'critical');
            
            echo json_encode([
                'success' => true,
                'message' => 'Digital attendance system disabled successfully',
                'action' => 'disabled'
            ]);
            break;
            
        case 'enable_system':
            // Enable digital attendance system
            $stmt = $conn->prepare("
                INSERT INTO system_settings (setting_key, value, updated_by, updated_at) 
                VALUES ('digital_attendance_enabled', '1', ?, ?)
                ON DUPLICATE KEY UPDATE 
                value = '1', updated_by = ?, updated_at = ?
            ");
            $stmt->execute([$cso_id, $current_time, $cso_id, $current_time]);
            
            // Log the action
            $stmt = $conn->prepare("
                INSERT INTO security_alerts (severity, alert_type, description, user_id, created_at, status)
                VALUES ('info', 'system_control', 'Digital attendance system enabled by CSO', ?, ?, 'active')
            ");
            $stmt->execute([$cso_id, $current_time]);
            
            // Send notification to all users
            $notification = "✅ NOTICE: Digital attendance system has been ENABLED by Security Officer. You can now use digital attendance.";
            sendSystemNotification($conn, $notification, 'info');
            
            echo json_encode([
                'success' => true,
                'message' => 'Digital attendance system enabled successfully',
                'action' => 'enabled'
            ]);
            break;
            
        case 'send_notification':
            // Send custom system notification
            if (empty($notification_message)) {
                echo json_encode(['success' => false, 'message' => 'Notification message is required']);
                exit;
            }
            
            sendSystemNotification($conn, $notification_message, 'warning');
            
            // Log the action
            $stmt = $conn->prepare("
                INSERT INTO security_alerts (severity, alert_type, description, user_id, created_at, status)
                VALUES ('warning', 'system_notification', ?, ?, ?, 'active')
            ");
            $stmt->execute([$notification_message, $cso_id, $current_time]);
            
            echo json_encode([
                'success' => true,
                'message' => 'System notification sent successfully'
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("Attendance system control error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}

function sendSystemNotification($conn, $message, $type) {
    try {
        // Use the existing notification flow system for role-based targeting
        $sender_role = $_SESSION['user_type'];
        
        // For system notifications, send to all relevant roles based on sender
        $recipient_roles = [];
        
        switch ($sender_role) {
            case 'cso':
                // CSO system notifications go to: admin, dept_manager, employee
                $recipient_roles = ['admin', 'dept_manager', 'employee'];
                break;
            case 'admin':
                // Admin system notifications go to: cso, hr, dept_manager, employee
                $recipient_roles = ['cso', 'hr', 'dept_manager', 'employee'];
                break;
            default:
                // Default: send to all roles
                $recipient_roles = ['admin', 'cso', 'hr', 'dept_manager', 'employee'];
        }
        
        // Send notifications using the proper flow system
        foreach ($recipient_roles as $recipient_role) {
            // Create notifications for each role using the correct table mapping
            $table_map = [
                'admin' => 'admins',
                'cso' => 'csos',
                'hr' => 'hr',
                'dept_manager' => 'dept_managers',
                'employee' => 'staff_profiles'
            ];
            
            $table = $table_map[$recipient_role] ?? null;
            if ($table) {
                if ($table === 'staff_profiles') {
                    // For employees, use staff_profiles table
                    $stmt = $conn->prepare("
                        INSERT INTO notifications (user_id, message, type, created_at, is_read)
                        SELECT sp.id, ?, ?, NOW(), 0
                        FROM staff_profiles sp
                        WHERE sp.status = 'Active'
                    ");
                    $stmt->execute([$message, $type]);
                } else {
                    // For other roles, use their respective tables
                    $stmt = $conn->prepare("
                        INSERT INTO notifications (user_id, message, type, created_at, is_read)
                        SELECT id, ?, ?, NOW(), 0
                        FROM $table
                        WHERE status = 'active'
                    ");
                    $stmt->execute([$message, $type]);
                }
            }
        }
        
        // Log to system notifications table
        $stmt = $conn->prepare("
            INSERT INTO system_notifications (message, type, created_at, sent_by)
            VALUES (?, ?, NOW(), ?)
        ");
        $stmt->execute([$message, $type, $_SESSION['user_id']]);
        
        error_log("System notification sent successfully: $message using role-based flow system");
        
    } catch (Exception $e) {
        error_log("System notification error: " . $e->getMessage());
    }
}
?> 