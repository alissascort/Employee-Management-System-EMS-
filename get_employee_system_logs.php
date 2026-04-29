<?php
session_start();
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Only allow logged-in employees
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'employee') {
    exit; // Stop if not authorized
}

try {
    $db = new Database();
    $conn = $db->connect();

    // Record login or other actions
    $stmt = $conn->prepare("INSERT INTO system_logs 
        (log_level, log_type, type, message, timestamp, ip_address, user_id) 
        VALUES (?, ?, ?, ?, NOW(), ?, ?)");
    $stmt->execute([
        'INFO',                    // log_level
        'authentication',          // log_type category
        'login_success',           // specific type
        "Employee {$_SESSION['full_name']} logged in", // message
        $_SERVER['REMOTE_ADDR'],   // IP address
        $_SESSION['user_id']       // user id
    ]);

} catch (PDOException $e) {
    error_log("Error logging employee activity: " . $e->getMessage());
}
?>
