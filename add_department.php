<?php

require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

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
    
    // Validate required fields
    $required_fields = ['department_name', 'status'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $response['message'] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            echo json_encode($response);
            exit;
        }
    }
    
    // Get form data
    $department_name = trim($_POST['department_name']);
    $status = $_POST['status'];
    $description = trim($_POST['description'] ?? '');
    $budget = floatval($_POST['budget'] ?? 0);
    $action = $_POST['action'] ?? 'add';
    $department_id = $_POST['department_id'] ?? null;
    
    // Validate department name length
    if (strlen($department_name) < 3 || strlen($department_name) > 50) {
        $response['message'] = 'Department name must be between 3 and 50 characters';
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'edit') {
        // Update existing department
        if (!$department_id) {
            $response['message'] = 'Department ID is required for editing';
            echo json_encode($response);
            exit;
        }
        
        // Check if department name already exists (excluding current department)
        $stmt = $conn->prepare("SELECT department_id FROM departments WHERE name = ? AND department_id != ?");
        $stmt->execute([$department_name, $department_id]);
        
        if ($stmt->fetch()) {
            $response['message'] = 'Department name already exists';
            echo json_encode($response);
            exit;
        }
        
        // Update department
        $stmt = $conn->prepare("
            UPDATE departments 
            SET name = ?, status = ?, description = ?, budget = ?
            WHERE department_id = ?
        ");
        
        $stmt->execute([
            $department_name,
            $status,
            $description,
            $budget,
            $department_id
        ]);
        
        $response['success'] = true;
        $response['message'] = 'Department updated successfully';
    } else {
        // Add new department
        // Check if department already exists
        $stmt = $conn->prepare("SELECT department_id FROM departments WHERE name = ?");
        $stmt->execute([$department_name]);
        
        if ($stmt->fetch()) {
            $response['message'] = 'Department with this name already exists';
            echo json_encode($response);
            exit;
        }
        
        // Insert new department
        $stmt = $conn->prepare("
            INSERT INTO departments (
                name, status, description, budget
            ) VALUES (
                ?, ?, ?, ?
            )
        ");
        
        $stmt->execute([
            $department_name,
            $status,
            $description,
            $budget
        ]);
        
        if ($stmt->rowCount() > 0) {
            $response = [
                'success' => true,
                'message' => 'Department created successfully',
                'department_id' => $conn->lastInsertId()
            ];
        } else {
            $response['message'] = 'Failed to create department';
        }
    }
    
} catch (PDOException $e) {
    error_log("Database Error in add_department.php: " . $e->getMessage());
    $response['message'] = 'Database error occurred: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Error in add_department.php: " . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

echo json_encode($response);
?>
