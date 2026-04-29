<?php
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Check if user is logged in as CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    $csoId = $_SESSION['user_id'];
    
    // Get CSO information
    $stmt = $conn->prepare("SELECT cso_id, cso_code, email, full_name, profile_photo, last_login FROM csos WHERE cso_id = ?");
    $stmt->execute([$csoId]);
    $cso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cso) {
        echo json_encode(['success' => false, 'message' => 'CSO not found']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'cso' => [
            'id' => $cso['cso_id'],
            'code' => $cso['cso_code'],
            'email' => $cso['email'],
            'full_name' => $cso['full_name'],
            'profile_photo' => $cso['profile_photo'],
            'last_login' => $cso['last_login']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error getting CSO session info: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?> 