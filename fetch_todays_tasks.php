<?php
header("Content-Type: application/json");
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'employee') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$employee_id = $_SESSION['user_id'];
$today = date('Y-m-d');

try {
    $db = new Database();
    $conn = $db->connect();
    // Adjust the table/fields as per your schema
    $stmt = $conn->prepare("SELECT task, project, priority, due_time, status FROM tasks WHERE employee_id = :employee_id AND task_date = :today ORDER BY due_time ASC");
    $stmt->bindParam(':employee_id', $employee_id);
    $stmt->bindParam(':today', $today);
    $stmt->execute();
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'tasks' => $tasks]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
