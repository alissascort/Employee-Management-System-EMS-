<?php
// Secure session cookie parameters
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

header('Content-Type: application/json');

// Check if session is valid and user is HR
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'hr') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';
$db = new Database();
$conn = $db->connect();

// ✅ Corrected query: use the hr table, not admins
$stmt = $conn->prepare("SELECT hr_id, email, full_name, role, profile_photo, status, last_login 
                        FROM hr 
                        WHERE hr_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$hr = $stmt->fetch(PDO::FETCH_ASSOC);

// Debug logging (shows up in error_log)
error_log('Session user_id: ' . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log('HR data: ' . print_r($hr, true));

if ($hr) {
    echo json_encode([
        'success' => true,
        'user' => $hr
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'HR record not found'
    ]);
}
?>
