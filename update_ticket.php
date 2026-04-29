<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Set secure session cookie parameters before starting session
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// === AUTHENTICATION CHECK ===
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$ticket_id = $input['ticket_id'] ?? null;
$status = $input['status'] ?? null;
$assigned_to = $input['assigned_to'] ?? null;
$assigned_role = $input['assigned_role'] ?? null;
$comment = $input['comment'] ?? null;

$user_id = $_SESSION['user_id'];
$user_type = strtolower($_SESSION['user_type']);

// === VALIDATION ===
if (!$ticket_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ticket ID required']);
    exit;
}

// Validate status (merged lists from both versions)
if ($status) {
    $validStatuses = ['open', 'assigned', 'in_progress', 'resolved', 'closed', 'escalated'];
    if (!in_array($status, $validStatuses)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid status. Allowed: ' . implode(', ', $validStatuses),
            'allowed_statuses' => $validStatuses
        ]);
        exit;
    }
}

try {
    $db = new Database();
    $pdo = $db->connect();

    // === TABLE CREATION SAFEGUARD ===
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        user_id INT NOT NULL,
        user_name VARCHAR(255) NOT NULL,
        user_role VARCHAR(50) NOT NULL,
        comment TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
    )");

    $pdo->beginTransaction();

    // === FETCH USER NAME BASED ON ROLE ===
    $userName = 'Unknown User';
    $userRole = $user_type;

    switch ($user_type) {
        case 'employee':
            $stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM employees WHERE employee_id = ?");
            break;
        case 'admin':
            $stmt = $pdo->prepare("SELECT full_name as name FROM admins WHERE admin_id = ?");
            break;
        case 'dept_manager':
            $stmt = $pdo->prepare("SELECT full_name as name FROM dept_managers WHERE manager_id = ? OR dm_id = ?");
            $stmt->execute([$user_id, $user_id]);
            break;
        case 'hr':
            $stmt = $pdo->prepare("SELECT full_name as name FROM hr WHERE hr_id = ?");
            break;
        case 'cso':
            $stmt = $pdo->prepare("SELECT full_name as name FROM csos WHERE cso_id = ?");
            break;
        default:
            $stmt = null;
    }

    if ($stmt) {
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $userName = $result['name'] ?? 'Unknown ' . ucfirst($user_type);
    }

    // === TICKET UPDATES ===
    if ($status && in_array($user_type, ['admin', 'dept_manager', 'hr', 'cso'])) {
        $stmt = $pdo->prepare('UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $ticket_id]);
        error_log("Status updated to: $status for ticket: $ticket_id");
    }

    if ($assigned_to && in_array($user_type, ['admin', 'dept_manager', 'hr', 'cso'])) {
        $stmt = $pdo->prepare('UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$assigned_to, $ticket_id]);
        error_log("Ticket assigned_to updated to: $assigned_to for ticket: $ticket_id");
    }

    if ($assigned_role && in_array($user_type, ['admin', 'dept_manager', 'hr', 'cso'])) {
        $stmt = $pdo->prepare('UPDATE tickets SET assigned_role = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$assigned_role, $ticket_id]);
        error_log("Ticket assigned_role updated to: $assigned_role for ticket: $ticket_id");
    }

    // === COMMENT ADDITION ===
    if ($comment) {
        $stmt = $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, user_name, user_role, comment) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$ticket_id, $user_id, $userName, $userRole, $comment]);
        error_log("Comment added by $userName ($userRole) on ticket $ticket_id");
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Ticket updated successfully',
        'updates' => [
            'status_updated' => (bool)$status,
            'assigned_updated' => (bool)$assigned_to,
            'role_updated' => (bool)$assigned_role,
            'comment_added' => (bool)$comment
        ],
        'allowed_statuses' => $validStatuses
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Ticket update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update ticket: ' . $e->getMessage()
    ]);
}
?>
