<?php
header("Content-Type: application/json");
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'employee') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$employee_id = $_SESSION['user_id'];

try {
    $db = new Database();
    $conn = $db->connect();
    // Adjust the table/fields as per your schema
    $stmt = $conn->prepare("SELECT task, project, completed_on, status, proof_url FROM tasks WHERE employee_id = :employee_id AND status = 'Completed' ORDER BY completed_on DESC");
    $stmt->bindParam(':employee_id', $employee_id);
    $stmt->execute();
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'tasks' => $tasks]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
