<?php
header('Content-Type: application/json');

require_once __DIR__ . '/db_connect.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['department_id']) || empty($input['manager_code'])) {
        throw new Exception('Department ID and Manager Code are required');
    }
    
    $db = (new Database())->connect();
    
    // First verify the manager exists
    $stmt = $db->prepare("SELECT id FROM staff_profiles WHERE employee_code = ? AND role LIKE '%Manager%'");
    $stmt->execute([$input['manager_code']]);
    if (!$stmt->fetch()) {
        throw new Exception('Invalid manager code or not a manager');
    }
    
    // Update the department
    $stmt = $db->prepare("UPDATE departments SET manager_code = ? WHERE department_id = ?");
    $success = $stmt->execute([$input['manager_code'], $input['department_id']]);
    
    if (!$success) {
        throw new Exception('Failed to assign manager');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Manager assigned successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
