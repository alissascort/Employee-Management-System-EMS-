<?php
header('Content-Type: application/json');
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

$data = json_decode(file_get_contents('php://input'), true);
$department = $data['department'] ?? '';
if (!$department) {
    echo json_encode(['success' => false, 'message' => 'No department']);
    exit;
}
require_once __DIR__ . '/db_connect.php';
$db = (new Database())->connect();
$stmt = $db->prepare("SELECT * FROM department_activities WHERE department = ? AND status = 'non-completed'");
$stmt->execute([$department]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Here you could email, log, or store the report as needed
// For demo, just return the data
echo json_encode(['success' => true, 'report' => $tasks]);
?>
