<?php
session_start();
header("Content-Type: application/json");

// === DEBUG LOGGING ===
error_log("=== SESSION CHECK START ===");
error_log("Session ID: " . session_id());
error_log("Session data: " . print_r($_SESSION, true));
error_log("=== SESSION CHECK END ===");

// === AUTHENTICATION CHECK ===
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo json_encode([
        'success' => false,
        'logged_in' => false,
        'message' => 'Not authenticated',
        'code' => 'NOT_AUTHENTICATED'
    ]);
    exit;
}

// === SESSION TIMEOUT (24 HOURS) ===
$session_max_age = 24 * 60 * 60; // 24 hours in seconds
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $session_max_age) {
    session_destroy();
    echo json_encode([
        'success' => false,
        'logged_in' => false,
        'message' => 'Session expired',
        'code' => 'SESSION_EXPIRED'
    ]);
    exit;
}

// === OPTIONAL: LEGACY EXPIRATION SUPPORT ===
if (isset($_SESSION['expires_at']) && time() > $_SESSION['expires_at']) {
    session_destroy();
    echo json_encode([
        'success' => false,
        'logged_in' => false,
        'message' => 'Session expired (legacy)',
        'code' => 'SESSION_EXPIRED'
    ]);
    exit;
}

// === ROLE VALIDATION (HR-SPECIFIC CHECK STILL INCLUDED) ===
if ($_SESSION['user_type'] === 'hr') {
    $role_message = 'HR session validated';
} elseif ($_SESSION['user_type'] === 'admin') {
    $role_message = 'Admin session validated';
} elseif ($_SESSION['user_type'] === 'employee') {
    $role_message = 'Employee session validated';
} else {
    $role_message = 'Unknown role';
}

// === UNIVERSAL SESSION RESPONSE ===
echo json_encode([
    'success' => true,
    'logged_in' => true,
    'message' => $role_message,
    'user' => [
        'id' => $_SESSION['user_id'],
        'email' => $_SESSION['email'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'user_type' => $_SESSION['user_type'],
        'login_time' => $_SESSION['login_time'] ?? time()
    ]
]);
?>
