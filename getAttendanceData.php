<?php
session_start();
header("Content-Type: application/json");
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Restrict access: HR only
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'hr') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';

try {
    $db = new Database();
    $conn = $db->connect();

    $stmt = $conn->prepare("
        SELECT 
            a.id,
            a.employee_code,
            e.first_name,
            e.last_name,
            e.department,
            a.date,
            a.check_in_time,
            a.check_out_time,
            TIMEDIFF(IFNULL(a.check_out_time, NOW()), a.check_in_time) AS duration,
            a.status,
            a.reason
        FROM attendance a
        JOIN employees e ON a.employee_code = e.employee_code
        ORDER BY a.date DESC, a.check_in_time DESC
        LIMIT 100
    ");
    $stmt->execute();
    $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($attendanceRecords);

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>
