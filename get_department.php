<?php
header('Content-Type: application/json');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();


try {
    if (!isset($_GET['id'])) {
        throw new Exception('Department ID is required');
    }
    
    $db = (new Database())->connect();
    
    $stmt = $db->prepare("SELECT * FROM departments WHERE department_id = ?");
    $stmt->execute([$_GET['id']]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$department) {
        throw new Exception('Department not found');
    }
    
    echo json_encode([
        'success' => true,
        'department' => $department
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
