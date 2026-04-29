<?php
session_start();
header("Content-Type: application/json");
require_once 'db_connect.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['employee_id'])) {
    echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
    exit;
}

$employeeId = intval($input['employee_id']);

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Start transaction
    $conn->beginTransaction();
    
    // Check if employee exists
    $checkStmt = $conn->prepare("SELECT employee_id FROM employees WHERE employee_id = :id");
    $checkStmt->bindParam(':id', $employeeId);
    $checkStmt->execute();
    
    if (!$checkStmt->fetch()) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit;
    }
    
    // Delete from staff_profiles first (due to foreign key constraints)
    $staffStmt = $conn->prepare("DELETE FROM staff_profiles WHERE employee_id = :id");
    $staffStmt->bindParam(':id', $employeeId);
    $staffStmt->execute();
    
    // Delete from employees table
    $employeeStmt = $conn->prepare("DELETE FROM employees WHERE employee_id = :id");
    $employeeStmt->bindParam(':id', $employeeId);
    $employeeStmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Employee deleted successfully'
    ]);
    
} catch (PDOException $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?> 