<?php
session_start();
header('Content-Type: application/json');

echo json_encode([
    'session_id' => session_id(),
    'user_id' => $_SESSION['user_id'] ?? 'not set',
    'user_type' => $_SESSION['user_type'] ?? 'not set',
    'email' => $_SESSION['email'] ?? 'not set',
    'full_name' => $_SESSION['full_name'] ?? 'not set',
    'all_session_data' => $_SESSION
]);
?> 