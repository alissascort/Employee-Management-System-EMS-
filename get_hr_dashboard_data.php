<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: application/json');

// Check if user is logged in and is HR
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'hr') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Get attendance data for the week
    $attendanceQuery = "SELECT 
        DAYNAME(date) as day_name,
        COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
        COUNT(CASE WHEN status = 'late' THEN 1 END) as late,
        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent
        FROM attendance 
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY date, DAYNAME(date)
        ORDER BY date";
    
    $stmt = $conn->prepare($attendanceQuery);
    $stmt->execute();
    $attendanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get leave requests data
    $leaveQuery = "SELECT 
        status,
        COUNT(*) as count
        FROM leave_requests 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY status";
    
    $stmt = $conn->prepare($leaveQuery);
    $stmt->execute();
    $leaveData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format data for charts
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $present = array_fill(0, 7, 0);
    $late = array_fill(0, 7, 0);
    $absent = array_fill(0, 7, 0);
    
    foreach ($attendanceData as $row) {
        $dayIndex = array_search(substr($row['day_name'], 0, 3), $days);
        if ($dayIndex !== false) {
            $present[$dayIndex] = (int)$row['present'];
            $late[$dayIndex] = (int)$row['late'];
            $absent[$dayIndex] = (int)$row['absent'];
        }
    }
    
    $leaveStats = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
    foreach ($leaveData as $row) {
        $leaveStats[strtolower($row['status'])] = (int)$row['count'];
    }
    
    echo json_encode([
        'success' => true,
        'attendance' => [
            'labels' => $days,
            'present' => $present,
            'late' => $late,
            'absent' => $absent
        ],
        'leave' => [
            'labels' => ['Approved', 'Pending', 'Rejected'],
            'data' => [$leaveStats['approved'], $leaveStats['pending'], $leaveStats['rejected']]
        ]
    ]);
    
} catch (Exception $e) {
    error_log("HR Dashboard Data Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
