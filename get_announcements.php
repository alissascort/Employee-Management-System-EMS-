<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';
$database = new Database();
$db = $database->getConnection();

try {
    $stmt = $db->prepare("SELECT * FROM announcements WHERE status = 'published' ORDER BY created_at DESC LIMIT 20");
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'announcements' => $announcements]);
} catch(PDOException $e) {
    // Table might not exist yet - return empty
    echo json_encode(['success' => true, 'announcements' => []]);
}
?>
