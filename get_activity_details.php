<?php
header('Content-Type: application/json');
$id = $_GET['id'] ?? '';
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'No activity ID']);
    exit;
}
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

$db = (new Database())->connect();
$stmt = $db->prepare("SELECT a.*, CONCAT(s.firstname, ' ', s.lastname) as employee_name FROM department_activities a LEFT JOIN staff_profiles s ON a.assigned_to = s.id WHERE a.id = ?");
$stmt->execute([$id]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);
if ($activity) {
    echo json_encode(['success' => true, 'activity' => $activity]);
} else {
    echo json_encode(['success' => false, 'message' => 'Not found']);
}
?>
