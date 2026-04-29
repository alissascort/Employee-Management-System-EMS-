<?php
header('Content-Type: application/json');

require_once __DIR__ . '/db_connect.php';

try {
    $db = (new Database())->connect();
    
    // Get all staff with manager roles
    $query = "SELECT employee_code, firstname, lastname, role, profile_photo 
              FROM staff_profiles 
              WHERE role LIKE '%Manager%' AND status = 'Active'
              ORDER BY firstname, lastname";
    
    $stmt = $db->query($query);
    $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'managers' => $managers
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load managers: ' . $e->getMessage()
    ]);
}
?>
