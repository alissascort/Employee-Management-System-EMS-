<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Log command to database (simplified example)
$logData = [
    'timestamp' => $input['timestamp'] ?? date('Y-m-d H:i:s'),
    'session_id' => $input['session_id'] ?? '',
    'user_id' => $_SESSION['user_id'] ?? 'unknown',
    'command' => $input['command'] ?? '',
    'output' => substr($input['output'] ?? '', 0, 1000), // Limit output length
    'status' => $input['status'] ?? 'unknown',
    'ip_address' => $_SERVER['REMOTE_ADDR']
];

// Append to log file (replace with database insert in production)
file_put_contents('terminal_command_log.json', json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);

echo json_encode(['success' => true]);
?>