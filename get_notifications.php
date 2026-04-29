<?php
// Set secure session config BEFORE starting the session
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Use only if using HTTPS
ini_set('session.use_strict_mode', 1);

// Start the session properly
// SECURE SESSION CONFIG (Development - HTTP)
ini_set('session.cookie_httponly', 1);       // Protect against XSS
ini_set('session.use_strict_mode', 1);       // Prevent session fixation
ini_set('session.cookie_secure', 0);         // Disable HTTPS-only (since localhost uses HTTP)
ini_set('session.cookie_samesite', 'Lax');   // Balance security & usability

session_start();

// Now safely access $_SESSION
header('Content-Type: application/json');
require_once 'db_connect.php';

// Access control
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = new Database();
$pdo = $db->connect();

$userId = $_SESSION['user_id'];
$readType = isset($_GET['read']) && $_GET['read'] === 'all' ? 'all' : 'unread';

try {
    // Get the correct user ID based on user type and their respective table
    $actualUserId = null;
    
    switch ($_SESSION['user_type']) {
        case 'employee':
            // For employees, get staff_profiles ID
            $stmt = $pdo->prepare("SELECT id FROM staff_profiles WHERE employee_id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $actualUserId = $result['id'] ?? null;
            break;
            
        case 'admin':
            // For admins, use admin_id directly
            $actualUserId = $userId;
            break;
            
        case 'cso':
            // For CSOs, use cso_id directly
            $actualUserId = $userId;
            break;
            
        case 'hr':
            // For HR, use hr_id directly
            $actualUserId = $userId;
            break;
            
        case 'dept_manager':
            // For Dept Managers, use dept_manager_id directly
            $actualUserId = $userId;
            break;
            
        default:
            $actualUserId = $userId;
    }
    
    if (!$actualUserId) {
        echo json_encode([
            'success' => true,
            'notifications' => [],
            'unread_count' => 0
        ]);
        exit;
    }
    
    if ($readType === 'all') {
        $stmt = $pdo->prepare("SELECT id, type, message, related_id, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
        $stmt->execute([$actualUserId]);
    } else {
        $stmt = $pdo->prepare("SELECT id, type, message, related_id, is_read, created_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$actualUserId]);
    }
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get unread count for notification badge
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$actualUserId]);
    $unreadCount = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unreadCount
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching notifications: ' . $e->getMessage()
    ]);
}
?>
