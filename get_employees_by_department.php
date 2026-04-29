<?php
session_start();
header("Content-Type: application/json");

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';

$response = ['success' => false, 'message' => 'An error occurred'];

try {
    $db = new Database();
    $conn = $db->connect();
    
    $department_id = $_GET['department_id'] ?? null;
    
    if (!$department_id) {
        $response['message'] = 'Department ID is required';
        echo json_encode($response);
        exit;
    }
    
    // Get department name first
    $stmt = $conn->prepare("SELECT name FROM departments WHERE department_id = ?");
    $stmt->execute([$department_id]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$department) {
        $response['message'] = 'Department not found';
        echo json_encode($response);
        exit;
    }
    
    // Get employees in this department
    $stmt = $conn->prepare("
        SELECT 
            employee_id,
            employee_code,
            first_name,
            last_name,
            position,
            status,
            email
        FROM employees 
        WHERE department = ?
        ORDER BY first_name, last_name
    ");
    $stmt->execute([$department['name']]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response = [
        'success' => true,
        'department_name' => $department['name'],
        'employees' => $employees
    ];

} catch (PDOException $e) {
    error_log("Database Error in get_employees_by_department.php: " . $e->getMessage());
    $response['message'] = 'Database error occurred: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Error in get_employees_by_department.php: " . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

echo json_encode($response);
?>
