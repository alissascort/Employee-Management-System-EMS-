<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'hr') exit;

try {
    $db = new Database();
    $conn = $db->connect();
    $stmt = $conn->prepare("INSERT INTO system_logs (log_level, log_type, type, message, timestamp, ip_address, user_id) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
    $stmt->execute([
        'INFO',
        'authentication',
        'login_success',
        "HR {$_SESSION['full_name']} logged in",
        $_SERVER['REMOTE_ADDR'],
        $_SESSION['user_id']
    ]);
} catch (PDOException $e) {
    error_log("Error logging HR activity: " . $e->getMessage());
}
?>
