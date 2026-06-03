<?php
session_start();
header('Content-Type: application/json');
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();

try {
    $stmt = $pdo->query("SELECT department_name as name, COUNT(*) as count FROM staff_profiles GROUP BY department_name");
    $data = $stmt->fetchAll();
    
    $labels = array_column($data, 'name');
    $values = array_map('intval', array_column($data, 'count'));
    
    echo json_encode(['success' => true, 'labels' => $labels, 'values' => $values]);
} catch(PDOException $e) {
    echo json_encode(['success' => true, 'labels' => [], 'values' => []]);
}
?>
