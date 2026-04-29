<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->connect();

    // Mark all notifications as read for the logged-in user
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);

    echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error marking notifications as read: ' . $e->getMessage()]);
}
?>
