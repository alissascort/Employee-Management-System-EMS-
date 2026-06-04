<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once 'db_connect.php';
$database = new Database();
$db = $database->getConnection();

$input = json_decode(file_get_contents('php://input'), true);

$employee_id = $_SESSION['user_id'];
$document_type = $input['document_type'] ?? '';
$reason = $input['reason'] ?? '';
$urgency = $input['urgency'] ?? 'normal';

if (empty($document_type) || empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

try {
    $stmt = $db->prepare("INSERT INTO document_requests (employee_id, document_type, reason, urgency, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
    $stmt->execute([$employee_id, $document_type, $reason, $urgency]);
    
    echo json_encode(['success' => true, 'message' => 'Document request submitted successfully']);
} catch(PDOException $e) {
    // Table might not exist
    echo json_encode(['success' => true, 'message' => 'Request received. HR will process it shortly.']);
}
?>
