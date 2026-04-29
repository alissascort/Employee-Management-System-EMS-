<?php
header("Content-Type: application/json");
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

// Enhanced debug logging
error_log("=== ADMIN DASHBOARD ACCESS ===");
error_log("Session ID: " . session_id());
error_log("Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log("Session user_type: " . ($_SESSION['user_type'] ?? 'NOT SET'));

// Enforce admin session
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    error_log("UNAUTHORIZED ACCESS ATTEMPT to admin dashboard");
    error_log("Session data: " . print_r($_SESSION, true));
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access - Admin required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();

    // === EMPLOYEE DATA ===
    $stmt = $conn->query("SELECT COUNT(*) FROM employees WHERE status = 'active'");
    $totalEmployees = (int)$stmt->fetchColumn();

    // Employee change comparison (last month)
    $lastMonth = date('Y-m-d', strtotime('-1 month'));
    $stmt = $conn->prepare("SELECT COUNT(*) FROM employees WHERE status = 'active' AND registration_date <= ?");
    $stmt->execute([$lastMonth]);
    $totalEmployeesLastMonth = (int)$stmt->fetchColumn();
    $employeeChange = $totalEmployeesLastMonth > 0 
        ? round((($totalEmployees - $totalEmployeesLastMonth) / $totalEmployeesLastMonth) * 100, 1)
        : 0;

    // === DEPARTMENTS ===
    $stmt = $conn->query("SELECT COUNT(*) FROM departments WHERE status = 'Active'");
    $totalDepartments = (int)$stmt->fetchColumn();

    // === ATTENDANCE ===
    $currentDate = date('Y-m-d');
    $attendanceStats = [
        'present' => 0,
        'present_late' => 0,
        'late' => 0,
        'absent' => 0,
        'no_record' => 0
    ];

    $stmt = $conn->prepare("
        SELECT 
            CASE WHEN a.status IS NULL THEN 'no_record' ELSE a.status END AS status,
            COUNT(*) AS count
        FROM employees e
        LEFT JOIN attendance a ON e.employee_code = a.employee_code AND a.date = ?
        WHERE e.status = 'active'
        GROUP BY CASE WHEN a.status IS NULL THEN 'no_record' ELSE a.status END
    ");
    $stmt->execute([$currentDate]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        if (isset($attendanceStats[$status])) {
            $attendanceStats[$status] = (int)$row['count'];
        }
    }

    $activeAttendanceToday = $attendanceStats['present'] + $attendanceStats['present_late'];
    $activeAttendancePercentage = $totalEmployees > 0 
        ? round(($activeAttendanceToday / $totalEmployees) * 100, 1)
        : 0;

    // Yesterday comparison
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $conn->prepare("
        SELECT 
            CASE WHEN a.status IS NULL THEN 'no_record' ELSE a.status END AS status,
            COUNT(*) AS count
        FROM employees e
        LEFT JOIN attendance a ON e.employee_code = a.employee_code AND a.date = ?
        WHERE e.status = 'active'
        GROUP BY CASE WHEN a.status IS NULL THEN 'no_record' ELSE a.status END
    ");
    $stmt->execute([$yesterday]);
    $yesterdayAttendance = [
        'present' => 0,
        'present_late' => 0,
        'late' => 0,
        'absent' => 0,
        'no_record' => 0
    ];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        if (isset($yesterdayAttendance[$status])) {
            $yesterdayAttendance[$status] = (int)$row['count'];
        }
    }

    $yesterdayActive = $yesterdayAttendance['present'] + $yesterdayAttendance['present_late'];
    $attendanceChange = $yesterdayActive > 0 ? $activeAttendanceToday - $yesterdayActive : 0;

   // === LEAVE REQUESTS ===
$leaveStats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0
];

$stmt = $conn->query("SELECT status, COUNT(*) AS count FROM leave_requests GROUP BY status");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status = strtolower($row['status']);
    if (isset($leaveStats[$status])) {
        $leaveStats[$status] = (int)$row['count'];
    }
}

// === NEW: Dynamic change tracking for pending/approved leaves ===
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekStart = date('Y-m-d', strtotime('monday this week'));
$today = date('Y-m-d');

// Pending change (compare yesterday vs today)
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) AS today_pending,
        SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) AS yesterday_pending
    FROM leave_requests
    WHERE status = 'pending'
");
$stmt->execute([$today, $yesterday]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$pendingChange = (int)$row['today_pending'] - (int)$row['yesterday_pending'];

// Approved change (compare yesterday vs today)
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) AS today_approved,
        SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) AS yesterday_approved
    FROM leave_requests
    WHERE status = 'approved'
");
$stmt->execute([$today, $yesterday]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$approvedChange = (int)$row['today_approved'] - (int)$row['yesterday_approved'];

// Approved this week (real data)
$stmt = $conn->prepare("
    SELECT COUNT(*) AS week_approved 
    FROM leave_requests 
    WHERE status = 'approved' 
      AND DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$weekStart, $today]);
$approvedThisWeek = (int)$stmt->fetch(PDO::FETCH_ASSOC)['week_approved'];

// Approved leaves today
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM leave_requests WHERE status = 'approved' AND DATE(created_at) = ?");
$stmt->execute([$today]);
$approvedToday = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Safe fallback if attendance table missing
if (!isset($activeAttendanceToday)) {
    $activeAttendanceToday = round($totalEmployees * 0.8);
    $activeAttendancePercentage = 80;
}

// === RESPONSE ===
$response = [
    'success' => true,
    'stats' => [
        'totalEmployees' => $totalEmployees,
        'totalEmployeesChange' => $employeeChange,
        'departments' => $totalDepartments,
        'attendance' => $attendanceStats,
        'activeAttendanceToday' => $activeAttendanceToday,
        'activeAttendancePercentage' => $activeAttendancePercentage,
        'attendanceChange' => $attendanceChange,
        'leave' => [
            'pending' => $leaveStats['pending'],
            'approved' => $leaveStats['approved'],
            'rejected' => $leaveStats['rejected']
        ],
        'pendingChange' => $pendingChange,
        'approvedChange' => $approvedChange,
        'approvedThisWeek' => $approvedThisWeek
    ]
];


    error_log("Dashboard response: " . json_encode($response));
    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Admin dashboard DB error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'An unexpected error occurred',
        'error' => $e->getMessage()
    ]);
}
?>
