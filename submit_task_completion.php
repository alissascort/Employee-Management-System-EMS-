<?php
session_start();
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Check if employee is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'employee') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate input
$requiredFields = ['task_id', 'completion_notes'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

$taskId = intval($input['task_id']);
$completionNotes = $input['completion_notes'];
$proofUrl = $input['proof_url'] ?? null; // Optional proof file URL

try {
    $db = new Database();
    $conn = $db->connect();

    // Verify the task belongs to this employee
    $taskStmt = $conn->prepare("SELECT * FROM tasks WHERE task_id = :task_id AND employee_id = :employee_id");
    $taskStmt->bindParam(':task_id', $taskId);
    $taskStmt->bindParam(':employee_id', $_SESSION['user_id']);
    $taskStmt->execute();
    $task = $taskStmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        echo json_encode(['success' => false, 'message' => 'Task not found or access denied']);
        exit;
    }

    if ($task['status'] === 'completed') {
        echo json_encode(['success' => false, 'message' => 'Task is already completed']);
        exit;
    }

    // Update task status to completed
    $updateStmt = $conn->prepare("UPDATE tasks SET 
        status = 'completed', 
        completion_proof = :proof_url,
        completed_at = NOW()
        WHERE task_id = :task_id AND employee_id = :employee_id");
    
    $updateStmt->bindParam(':proof_url', $proofUrl);
    $updateStmt->bindParam(':task_id', $taskId);
    $updateStmt->bindParam(':employee_id', $_SESSION['user_id']);

    if ($updateStmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Task completed successfully',
            'task_id' => $taskId
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update task']);
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?> 