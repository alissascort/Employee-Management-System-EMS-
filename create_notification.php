<?php
// create_notification.php
header('Content-Type: application/json');
require_once 'db_connect.php';
session_start();

// Only allow logged-in users
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$sender_id = $_SESSION['user_id'];
$sender_role = $_SESSION['user_type'];
$type = $input['type'] ?? '';
$message = $input['message'] ?? '';
$related_id = $input['related_id'] ?? null;
$recipient_role = $input['recipient_role'] ?? null;
$recipient_id = $input['recipient_id'] ?? null;

if (!$type || !$message || (!$recipient_role && !$recipient_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Notification flow rules
function can_notify($sender_role, $recipient_role) {
    $flow = [
        'admin' => ['cso', 'hr', 'dept_manager', 'employee', 'admin'],
        'cso' => ['admin', 'dept_manager', 'employee'],
        'hr' => ['admin', 'dept_manager', 'employee'],
        'dept_manager' => ['employee', 'hr', 'admin'],
        'employee' => ['dept_manager', 'hr', 'cso'],
    ];
    $sender_role = strtolower($sender_role);
    $recipient_role = strtolower($recipient_role);
    return isset($flow[$sender_role]) && in_array($recipient_role, $flow[$sender_role]);
}

try {
    $db = new Database();
    $pdo = $db->connect();
    $recipients = [];
    if ($recipient_id) {
        // Direct to a specific user
        $stmt = $pdo->prepare('SELECT id, user_type FROM users WHERE id = ?');
        $stmt->execute([$recipient_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new Exception('Recipient not found');
        if (!can_notify($sender_role, $user['user_type'])) {
            throw new Exception('Not allowed to notify this user');
        }
        $recipients[] = $user['id'];
    } else if ($recipient_role) {
        // To all users of a role
        if (!can_notify($sender_role, $recipient_role)) {
            throw new Exception('Not allowed to notify this role');
        }
        // Map role to table
        $role_table_map = [
            'admin' => 'admins',
            'cso' => 'csos',
            'hr' => 'hr',
            'dept_manager' => 'dept_managers',
            'employee' => 'employees',
        ];
        $table = $role_table_map[strtolower($recipient_role)] ?? null;
        if (!$table) throw new Exception('Invalid recipient role');
        $stmt = $pdo->query("SELECT id FROM $table");
        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    if (empty($recipients)) throw new Exception('No recipients found');
    $now = date('Y-m-d H:i:s');
    $insertStmt = $pdo->prepare('INSERT INTO notifications (user_id, type, message, related_id, is_read, created_at) VALUES (?, ?, ?, ?, 0, ?)');
    foreach ($recipients as $uid) {
        $insertStmt->execute([$uid, $type, $message, $related_id, $now]);
    }
    echo json_encode(['success' => true, 'message' => 'Notification(s) sent']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 