<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();


if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing request ID']);
    exit;
}

$id = intval($_GET['id']);
$db = new Database();
$conn = $db->connect();

// JOIN with staff_profiles to get name, department, email
$stmt = $conn->prepare("
    SELECT prr.*, sp.firstname, sp.lastname, sp.department, sp.email
    FROM password_recovery_requests prr
    LEFT JOIN staff_profiles sp ON prr.employee_code = sp.employee_code
    WHERE prr.id = ?
");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if ($request) {
    // Combine first and last name for display
    $request['name'] = trim(($request['firstname'] ?? '') . ' ' . ($request['lastname'] ?? ''));
    echo json_encode(['success' => true, 'request' => $request]);
} else {
    echo json_encode(['success' => false, 'message' => 'Request not found']);
}
?>
