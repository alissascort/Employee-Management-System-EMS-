<?php
header('Content-Type: application/json');

require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

require_once __DIR__ . '/db_connect.php';

try {
    if (!isset($_GET['id'])) {
        throw new Exception('Department ID is required');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    if (empty($input['name'])) {
        throw new Exception('Department name is required');
    }
    
    if (!isset($input['status']) || !in_array($input['status'], ['Active', 'Inactive'])) {
        throw new Exception('Invalid status value');
    }
    
    $db = (new Database())->connect();
    
    $stmt = $db->prepare("UPDATE departments SET 
                         name = ?, 
                         description = ?, 
                         status = ?,
                         budget = ? 
                         WHERE department_id = ?");
    
    $success = $stmt->execute([
        $input['name'],
        $input['description'] ?? null,
        $input['status'],
        $input['budget'] ?? 0,
        $_GET['id']
    ]);
    
    if (!$success) {
        throw new Exception('Failed to update department');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Department updated successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
