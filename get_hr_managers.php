<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

try {
    $database = new Database();
    $conn = $database->connect();
    
    $query = "SELECT employee_id, first_name, last_name, department 
              FROM employees 
              WHERE role = 'HR' OR role = 'hr_manager' 
              AND status = 'Active' 
              ORDER BY first_name, last_name";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'managers' => $managers
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error loading HR managers: ' . $e->getMessage()
    ]);
}
?>