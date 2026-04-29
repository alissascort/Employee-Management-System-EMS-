<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$priority = $input['priority'] ?? 'medium';
$assigned_role = $input['assigned_role'] ?? null;
$department = $input['department'] ?? null;
$created_by = $_SESSION['user_id'];
if (!$title || !$description) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Title and description required']);
    exit;
}
try {
    $db = new Database();
    $pdo = $db->connect();
   $category = !empty($input['category']) ? $input['category'] : 'General';
   $stmt = $pdo->prepare('INSERT INTO tickets (title, description, category, priority, assigned_role, department, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
   $stmt->execute([$title, $description, $category, $priority, $assigned_role, $department, $created_by]);
    $ticket_id = $pdo->lastInsertId();
    echo json_encode(['success' => true, 'ticket_id' => $ticket_id]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}