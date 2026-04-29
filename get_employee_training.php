<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'hr') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    $stmt = $conn->prepare("SELECT t.id, s.firstname, s.lastname, s.department, t.training_title, t.status, t.due_date, t.completion_date FROM trainings t JOIN staff_profiles s ON t.employee_id = s.id ORDER BY t.due_date DESC, s.lastname ASC");
    $stmt->execute();
    $trainings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'trainings' => $trainings]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 