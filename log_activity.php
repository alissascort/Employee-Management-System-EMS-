<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Database connection
try {
    $db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$employeeCode = $_SESSION['employee_code'];
$action = $_POST['action'] ?? '';
$type = $_POST['type'] ?? '';

if ($action === 'password_change') {
    $stmt = $db->prepare("INSERT INTO system_logs (employee_code, action_type, description, ip_address) 
                         VALUES (?, 'password_change', ?, ?)");
    $stmt->execute([
        $employeeCode, 
        "Password changed via $type",
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
    ]);
}

echo json_encode(['success' => true]);
?>
