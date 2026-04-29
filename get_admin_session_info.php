<?php
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Set session cookie parameters before starting session
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';
$db = new Database();
$conn = $db->connect();

$stmt = $conn->prepare("SELECT admin_id, email, full_name, role, profile_photo FROM admins WHERE admin_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Debug logging
error_log('Session user_id: ' . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log('Admin data: ' . print_r($admin, true));

echo json_encode([
    'success' => true,
    'user' => $admin
]);
?> 