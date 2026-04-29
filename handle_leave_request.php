<?php
header('Content-Type: application/json');
require_once 'db_connect.php'; // Your database connection file
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Verify request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

// Get and validate input
$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['request_id']) || !isset($input['action'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid input']));
}

$requestId = (int)$input['request_id'];
$action = strtoupper($input['action']);
$notes = isset($input['notes']) ? trim($input['notes']) : null;

// Validate action
if (!in_array($action, ['APPROVE', 'DENY'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid action']));
}

try {
    // ✅ FIX: Add database connection
    $db = new Database();
    $pdo = $db->connect();

    // Begin transaction
    $pdo->beginTransaction();
    
    // 1. Get current request details
    $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE leave_id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        throw new Exception('Leave request not found');
    }
    
    // 2. Update the request
    $newStatus = $action === 'APPROVE' ? 'APPROVED' : 'REJECTED';
    $updateStmt = $pdo->prepare("
    UPDATE leave_requests 
    SET status = ?, admin_notes = ?, processed_at = NOW() 
    WHERE leave_id = ?
");

    $updateStmt->execute([$newStatus, $notes, $requestId]);
    
    // 3. Create notification for the employee
    $notificationStmt = $pdo->prepare("
        INSERT INTO notifications 
        (user_id, type, message, related_id, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $notificationStmt->execute([
        $request['employee_id'],
        'leave_status',
        "Your leave request has been $newStatus",
        $requestId
    ]);
    
    // Commit transaction
    $pdo->commit();
    
    // Return success
    echo json_encode([
        'success' => true,
        'message' => "Leave request $newStatus successfully",
        'status' => $newStatus,
        'notification_id' => $pdo->lastInsertId()
    ]);
    
} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error processing request: ' . $e->getMessage()
    ]);
}
?>

