<?php
session_start();
header("Content-Type: application/json");
require_once 'db_connect.php';

// Check if user is logged in and is a Department Manager
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Dept Manager') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate input
$requiredFields = ['employee_id', 'title', 'description', 'project', 'priority', 'due_date', 'due_time'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

$employeeId = intval($input['employee_id']);
$title = $input['title'];
$description = $input['description'];
$project = $input['project'];
$priority = $input['priority'];
$dueDate = $input['due_date'];
$dueTime = $input['due_time'];

// Validate priority
if (!in_array($priority, ['low', 'medium', 'high'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid priority level']);
    exit;
}

// Validate date format
if (!strtotime($dueDate)) {
    echo json_encode(['success' => false, 'message' => 'Invalid due date format']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();

    // Verify the employee exists and belongs to the manager's department
    $employeeStmt = $conn->prepare("SELECT e.* FROM employees e 
                                   JOIN staff_profiles sp ON e.employee_id = sp.employee_id 
                                   WHERE e.employee_id = :employee_id AND sp.department = :manager_department");
    $employeeStmt->bindParam(':employee_id', $employeeId);
    $employeeStmt->bindParam(':manager_department', $_SESSION['department']);
    $employeeStmt->execute();
    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        echo json_encode(['success' => false, 'message' => 'Employee not found or not in your department']);
        exit;
    }

    // Insert new task
    $stmt = $conn->prepare("INSERT INTO tasks 
        (employee_id, title, description, project, priority, due_date, due_time, status) 
        VALUES (:employee_id, :title, :description, :project, :priority, :due_date, :due_time, 'pending')");
    
    $stmt->bindParam(':employee_id', $employeeId);
    $stmt->bindParam(':title', $title);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':project', $project);
    $stmt->bindParam(':priority', $priority);
    $stmt->bindParam(':due_date', $dueDate);
    $stmt->bindParam(':due_time', $dueTime);

    if ($stmt->execute()) {
        $taskId = $conn->lastInsertId();
        
        // Create notification for the employee
        $notificationStmt = $conn->prepare("INSERT INTO notifications 
            (user_id, type, message, related_id, created_at) 
            VALUES (?, 'task_assigned', ?, ?, NOW())");
        $notificationStmt->execute([
            $employeeId,
            "New task assigned: $title",
            $taskId
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Task assigned successfully',
            'task_id' => $taskId
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to assign task']);
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?> 