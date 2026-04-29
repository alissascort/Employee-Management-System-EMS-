<?php
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

header('Content-Type: application/json');
$id = $_GET['id'] ?? '';
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'No ID']);
    exit;
}
$config = require '/home/pablo/Desktop/FSM.ESM-config/.env.php';
try {
    $db = new PDO(
        'mysql:host=' . $config['DB_HOST'] . ';dbname=' . $config['DB_NAME'],
        $config['DB_USER'],
        $config['DB_PASS']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Join with staff_profiles to get employee info
    $stmt = $db->prepare("
        SELECT r.*, 
               s.firstname, s.lastname, s.department, s.email
        FROM password_recovery_requests r
        LEFT JOIN staff_profiles s ON r.employee_code = s.employee_code
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($req) {
        // Compose full name
        $req['name'] = trim(($req['firstname'] ?? '') . ' ' . ($req['lastname'] ?? ''));
        echo json_encode(['success' => true, 'request' => $req]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>