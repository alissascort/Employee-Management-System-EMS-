<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
header("Content-Type: application/json; charset=UTF-8");

require_once 'db_connect.php'; // PDO connection
$database = new Database();
$db = $database->getConnection();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
   echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

// Ensure only HR can create announcements (optional)
if ($_SESSION['user_type'] !== 'hr') {
   echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get POST data
$title   = trim($_POST['title'] ?? '');
$message = trim($_POST['message'] ?? '');
$audience = $_POST['audience'] ?? 'all';
$role = $_POST['target_role'] ?? null;
$department = $_POST['target_department_id'] ?? null;
$specificUsers = $_POST['user_ids'] ?? []; // comma-separated string

if ($title === '' || $message === '') {
  echo json_encode(['success' => false, 'message' => 'Title and message required.']);
    exit;
}

// Convert specific users to array if string
if (is_string($specificUsers)) {
    $specificUsers = array_map('intval', explode(',', $specificUsers));
}

try {
    // Insert announcement
    $stmt = $db->prepare("
        INSERT INTO announcements
        (title, message, audience, target_role, target_department_id, posted_by, date_posted, status)
        VALUES (:title, :message, :audience, :role, :department, :posted_by, NOW(), 'active')
    ");

    $stmt->execute([
        ':title' => $title,
        ':message' => $message,
        ':audience' => $audience,
        ':role' => $role,
        ':department' => $department,
        ':posted_by' => $_SESSION['full_name'] ?? 'Unknown'
    ]);

    $announcementId = $db->lastInsertId();

    // Insert specific recipients if audience is 'specific'
    if ($audience === 'specific' && !empty($specificUsers)) {
        $stmt = $db->prepare("
            INSERT INTO announcement_recipients (announcement_id, user_id)
            VALUES (:announcement_id, :user_id)
        ");
        foreach ($specificUsers as $userId) {
            $stmt->execute([
                ':announcement_id' => $announcementId,
                ':user_id' => $userId
            ]);
        }
    }

   echo json_encode(['success' => true, 'message' => 'Announcement created successfully!']);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'false',
        'message' => 'Failed to create announcement.',
        'details' => $e->getMessage()
    ]);
}
?>