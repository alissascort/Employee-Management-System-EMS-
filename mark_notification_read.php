<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = new Database();
$pdo = $db->connect();

$userId = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];
$notificationId = $_POST['notification_id'] ?? null;

try {
    // Get the staff_profiles ID for the current user
    $staffProfileId = null;
    
    // Find the staff_profiles ID based on user type
    switch ($userType) {
        case 'employee':
            $stmt = $pdo->prepare("SELECT id FROM staff_profiles WHERE employee_id = ?");
            $stmt->execute([$userId]);
            break;
        case 'admin':
            $stmt = $pdo->prepare("SELECT id FROM staff_profiles WHERE employee_id = ? AND role LIKE '%Administrator%'");
            $stmt->execute([$userId]);
            break;
        case 'cso':
            $stmt = $pdo->prepare("SELECT id FROM staff_profiles WHERE employee_id = ? AND role LIKE '%Security%'");
            $stmt->execute([$userId]);
            break;
    }
    
    $staffProfile = $stmt->fetch(PDO::FETCH_ASSOC);
    $staffProfileId = $staffProfile['id'] ?? null;
    
    if (!$staffProfileId) {
        echo json_encode([
            'success' => false,
            'message' => 'User profile not found'
        ]);
        exit;
    }
    
    if ($notificationId) {
        // Mark specific notification as read
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $staffProfileId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Notification marked as read'
        ]);
    } else {
        // Mark all notifications as read
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$staffProfileId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'All notifications marked as read'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error marking notification as read: ' . $e->getMessage()
    ]);
}
?>
