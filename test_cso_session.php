<?php
session_start();
header('Content-Type: application/json');

echo json_encode([
    'session_id' => session_id(),
    'user_id' => $_SESSION['user_id'] ?? 'NOT_SET',
    'user_type' => $_SESSION['user_type'] ?? 'NOT_SET',
    'email' => $_SESSION['email'] ?? 'NOT_SET',
    'full_name' => $_SESSION['full_name'] ?? 'NOT_SET',
    'all_session_data' => $_SESSION
]);
?> 