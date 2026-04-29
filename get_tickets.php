<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$user_id = $_SESSION['user_id'];
$user_type = strtolower($_SESSION['user_type']);
try {
    $db = new Database();
    $pdo = $db->connect();
    if ($user_type === 'admin') {
        $stmt = $pdo->query('SELECT * FROM tickets ORDER BY created_at DESC');
    } else if ($user_type === 'dept_manager') {
        // Assume department is stored in session
        $department = $_SESSION['department'] ?? '';
        $stmt = $pdo->prepare('SELECT * FROM tickets WHERE department = ? ORDER BY created_at DESC');
        $stmt->execute([$department]);
    } else if ($user_type === 'hr' || $user_type === 'cso') {
        $stmt = $pdo->prepare('SELECT * FROM tickets WHERE assigned_role = ? ORDER BY created_at DESC');
        $stmt->execute([$user_type]);
    } else {
        // Employee
        $stmt = $pdo->prepare('SELECT * FROM tickets WHERE created_by = ? ORDER BY created_at DESC');
        $stmt->execute([$user_id]);
    }
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'tickets' => $tickets]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 