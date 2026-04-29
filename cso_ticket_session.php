<?php
// Set session cookie parameters before starting session
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
header("Content-Type: application/json");
require_once 'db_connect.php';

// Check if user is logged in as CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode([
        'success' => false, 
        'message' => 'Unauthorized access. Please login as CSO.',
        'redirect' => 'FSM.ESM.CSO.html'
    ]);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Get CSO details
    $stmt = $conn->prepare("SELECT cso_id, email, full_name, role, profile_photo FROM cso WHERE cso_id = ? AND email = ?");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['email']]);
    
    if ($stmt->rowCount() > 0) {
        $cso = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $user_data = [
            'success' => true,
            'id' => $cso['cso_id'],
            'full_name' => $cso['full_name'],
            'email' => $cso['email'],
            'role' => $cso['role'],
            'profile_photo' => $cso['profile_photo'] ?: 'Parrot.JPG',
            'user_type' => 'cso',
            'dashboard_source' => 'cso_dashboard'
        ];
        
        echo json_encode($user_data);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'CSO not found',
            'redirect' => 'FSM.ESM.CSO.html'
        ]);
    }
    
} catch (PDOException $e) {
    error_log('CSO Ticket Session Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error',
        'redirect' => 'FSM.ESM.CSO.html'
    ]);
}
?>