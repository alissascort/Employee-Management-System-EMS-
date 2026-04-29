<?php
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// get_all_announcements.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once 'db_connect.php'; // Use the same PDO connection as create_announcement.php

// Check if user is logged in and is HR
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'hr') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied. HR only.']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // Get all announcements without any filtering for HR management view
    $query = "SELECT * FROM announcements ORDER BY date_posted DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Return the announcements as JSON
   echo json_encode(['success' => true, 'data' => $announcements]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'false',
        'message' => 'Failed to load announcements',
        'details' => $e->getMessage()
    ]);
}
?>