<?php
session_start();
header('Content-Type: application/json');
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();

try {
    $stmt = $pdo->query("SELECT DATE_FORMAT(created_at, '%b') as month, COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY MONTH(created_at) ORDER BY created_at");
    $data = $stmt->fetchAll();
    
    $labels = array_column($data, 'month');
    $values = array_map('intval', array_column($data, 'count'));
    
    echo json_encode(['success' => true, 'labels' => $labels, 'values' => $values]);
} catch(PDOException $e) {
    $months = ['Jan','Feb','Mar','Apr','May','Jun'];
    echo json_encode(['success' => true, 'labels' => $months, 'values' => [0,0,0,0,0,0]]);
}
?>
