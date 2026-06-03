<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();

$input = json_decode(file_get_contents('php://input'), true);

try {
    $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, can_manage_users, can_manage_payroll, can_manage_attendance, can_view_reports, can_configure_system, can_view_audit) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE can_manage_users=VALUES(can_manage_users), can_manage_payroll=VALUES(can_manage_payroll), can_manage_attendance=VALUES(can_manage_attendance), can_view_reports=VALUES(can_view_reports), can_configure_system=VALUES(can_configure_system), can_view_audit=VALUES(can_view_audit)");
    $stmt->execute([$input['role_id'], $input['can_manage_users'], $input['can_manage_payroll'], $input['can_manage_attendance'], $input['can_view_reports'], $input['can_configure_system'], $input['can_view_audit']]);
    
    echo json_encode(['success' => true, 'message' => 'Permissions saved']);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
