<?php
header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$activity_id = $data['activity_id'] ?? '';
$decision = $data['decision'] ?? '';
$new_due_date = $data['new_due_date'] ?? null;

if (!$activity_id || !in_array($decision, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}
require_once __DIR__ . '/db_connect.php';
$db = (new Database())->connect();

if ($decision === 'approve') {
    if (!$new_due_date) {
        echo json_encode(['success' => false, 'message' => 'New due date required']);
        exit;
    }
    $stmt = $db->prepare("UPDATE department_activities SET due_date = ?, admin_extension = 1 WHERE id = ?");
    $stmt->execute([$new_due_date, $activity_id]);
} else {
    // Reject: mark as not accessible
    $stmt = $db->prepare("UPDATE department_activities SET admin_extension = 0, status = 'locked' WHERE id = ?");
    $stmt->execute([$activity_id]);
}
echo json_encode(['success' => true]);
?>
