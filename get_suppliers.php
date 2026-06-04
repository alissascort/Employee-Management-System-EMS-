<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();

try {
    // This is a template endpoint - queries should be customized per table
    echo json_encode([
        'success' => true,
        'data' => [],
        'message' => 'Endpoint ready: get_suppliers.php'
    ]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
