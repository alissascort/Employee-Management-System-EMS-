<?php
/**
 * mark_vuln_fixed.php
 * Allows the CSO to mark a vulnerability as fixed or re-open it.
 */
header('Content-Type: application/json');
require_once 'db_connect.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$id     = isset($input['id']) ? (int)$input['id'] : 0;
$action = $input['action'] ?? 'fix'; // 'fix' or 'reopen'

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid vulnerability ID']);
    exit;
}

try {
    $db   = new Database();
    $conn = $db->connect();

    $newStatus = ($action === 'fix') ? 'fixed' : 'open';
    $stmt = $conn->prepare("UPDATE vulnerability_scans SET status=?, fixed_by=?, fixed_at=? WHERE id=?");
    $stmt->execute([
        $newStatus,
        ($action === 'fix') ? $_SESSION['user_id'] : null,
        ($action === 'fix') ? date('Y-m-d H:i:s') : null,
        $id
    ]);

    echo json_encode([
        'success' => true,
        'message' => $action === 'fix' ? 'Marked as fixed' : 'Re-opened',
        'new_status' => $newStatus
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
