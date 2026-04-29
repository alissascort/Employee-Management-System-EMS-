<?php
session_start();
header('Content-Type: application/json');

// Simulate CSO login
$_SESSION['user_id'] = 1;
$_SESSION['user_type'] = 'cso';
$_SESSION['email'] = 'brightonliston255@gmail.com';
$_SESSION['full_name'] = 'Brighton liston';

echo json_encode([
    'success' => true,
    'message' => 'Test CSO session created',
    'session_data' => $_SESSION
]);
?> 