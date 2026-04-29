<?php
header('Content-Type: application/json');
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

$input = json_decode(file_get_contents("php://input"), true);
$email = trim($input['email'] ?? '');
$name = trim($input['name'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($name) < 2) {
    echo json_encode(['success' => false, 'message' => 'Invalid email or name']);
    exit;
}

// ✅ Forward to send_material.php using cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost/send_material.php");  // adjust path if needed
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => $email, 'name' => $name]));

$response = curl_exec($ch);
curl_close($ch);

echo $response;
?>
