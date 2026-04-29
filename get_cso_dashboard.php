<?php
session_start();
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();


// Check if user is logged in as CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $database = new Database();
    $conn = $database->connect();

    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $currentDate = date('Y-m-d');

    // Get today's attendance statistics
    $stmt = $conn->prepare("
        SELECT 
            CASE 
                WHEN a.status IS NULL THEN 'no_record'
                ELSE a.status
            END as status,
            COUNT(*) as count
        FROM employees e
        LEFT JOIN attendance a ON e.employee_code = a.employee_code AND a.date = ?
        WHERE e.status = 'active'
        GROUP BY 
            CASE 
                WHEN a.status IS NULL THEN 'no_record'
                ELSE a.status
            END
    ");
    $stmt->execute([$currentDate]);
    
    $attendanceStats = [
        'present' => 0,
        'present_late' => 0,
        'late' => 0,
        'absent' => 0,
        'no_record' => 0
    ];
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        if (isset($attendanceStats[$status])) {
            $attendanceStats[$status] = (int)$row['count'];
        }
    }

    // Calculate totals
    $punctualToday = $attendanceStats['present'];
    $lateToday = $attendanceStats['present_late'] + $attendanceStats['late'];
    $totalAttendanceToday = $punctualToday + $lateToday + $attendanceStats['absent'];

    // Get total active employees
    $stmt = $conn->query("SELECT COUNT(*) FROM employees WHERE status = 'active'");
    $totalEmployees = (int)$stmt->fetchColumn();

    // Get recent attendance records for time book
    $stmt = $conn->prepare("
        SELECT 
            e.employee_code,
            e.first_name,
            e.last_name,
            a.check_in_time,
            a.check_out_time,
            a.total_hours,
            a.status
        FROM employees e
        LEFT JOIN attendance a ON e.employee_code = a.employee_code AND a.date = ?
        WHERE e.status = 'active'
        ORDER BY a.check_in_time DESC
        LIMIT 10
    ");
    $stmt->execute([$currentDate]);
    
    $recentAttendance = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $recentAttendance[] = [
            'employee_code' => $row['employee_code'],
            'name' => $row['first_name'] . ' ' . $row['last_name'],
            'check_in_time' => $row['check_in_time'] ? date('h:i A', strtotime($row['check_in_time'])) : '-',
            'check_out_time' => $row['check_out_time'] ? date('h:i A', strtotime($row['check_out_time'])) : '-',
            'total_hours' => $row['total_hours'] ? number_format($row['total_hours'], 2) : '-',
            'status' => $row['status'] ? ucfirst(str_replace('_', ' ', $row['status'])) : 'No Record'
        ];
    }

    echo json_encode([
        'success' => true,
        'stats' => [
            'punctualToday' => $punctualToday,
            'lateToday' => $lateToday,
            'totalAttendanceToday' => $totalAttendanceToday,
            'totalEmployees' => $totalEmployees
        ],
        'recentAttendance' => $recentAttendance
    ]);

} catch (PDOException $e) {
    error_log("CSO dashboard DB error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("CSO dashboard error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred']);
}
?> 