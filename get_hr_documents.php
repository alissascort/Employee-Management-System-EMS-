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
    $stmt = $conn->prepare("SELECT id, title, document_type, file_name, upload_date, uploaded_by FROM hr_documents ORDER BY upload_date DESC");
    $stmt->execute();
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'documents' => $documents]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 