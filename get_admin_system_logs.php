<?php
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') exit;

try {
    $db = new Database();
    $conn = $db->connect();
    $stmt = $conn->prepare("INSERT INTO system_logs (log_level, log_type, type, message, timestamp, ip_address, user_id) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
    $stmt->execute([
        'INFO',
        'authentication',
        'login_success',
        "Admin {$_SESSION['full_name']} logged in",
        $_SERVER['REMOTE_ADDR'],
        $_SESSION['user_id']
    ]);
} catch (PDOException $e) {
    error_log("Error logging admin activity: " . $e->getMessage());
}
?>
