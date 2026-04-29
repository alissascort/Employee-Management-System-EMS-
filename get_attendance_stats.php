<?php
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    $current_date = date('Y-m-d');
    
    // Get attendance stats for today
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_attendance,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN status = 'present_late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count
        FROM attendance 
        WHERE date = ?
    ");
    $stmt->execute([$current_date]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total' => (int)$stats['total_attendance'],
            'present' => (int)$stats['present_count'],
            'late' => (int)$stats['late_count'],
            'absent' => (int)$stats['absent_count']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Attendance stats error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred',
        'stats' => [
            'total' => 0,
            'present' => 0,
            'late' => 0,
            'absent' => 0
        ]
    ]);
}
?> 