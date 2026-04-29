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
    $stmt = $conn->prepare("SELECT r.id, r.position, a.name as applicant_name, a.email, a.phone, r.status, r.application_date FROM recruitment_applications r JOIN applicants a ON r.applicant_id = a.id ORDER BY r.application_date DESC");
    $stmt->execute();
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'applications' => $applications]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 