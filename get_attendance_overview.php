<?php
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Enforce admin session
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    error_log("Session variables: " . print_r($_SESSION, true));
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    $currentDate = date('Y-m-d');
    
    // Get today's attendance overview
    $stmt = $conn->prepare("
        SELECT 
            e.employee_code,
            e.first_name,
            e.last_name,
            e.department,
            e.position,
            a.status,
            a.check_in_time,
            a.check_out_time,
            a.reason,
            CASE 
                WHEN a.status IS NULL THEN 'No Record'
                ELSE a.status
            END as attendance_status
        FROM employees e
        LEFT JOIN attendance a ON e.employee_code = a.employee_code AND a.date = ?
        WHERE e.status = 'active'
        ORDER BY e.first_name, e.last_name
    ");
    
    $stmt->execute([$currentDate]);
    $attendanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary statistics
    $summary = [
        'total_employees' => 0,
        'present' => 0,
        'present_late' => 0,
        'late' => 0,
        'absent' => 0,
        'no_record' => 0
    ];
    
    foreach ($attendanceData as $record) {
        $summary['total_employees']++;
        
        switch ($record['attendance_status']) {
            case 'present':
                $summary['present']++;
                break;
            case 'present_late':
                $summary['present_late']++;
                break;
            case 'late':
                $summary['late']++;
                break;
            case 'absent':
                $summary['absent']++;
                break;
            case 'No Record':
                $summary['no_record']++;
                break;
        }
    }
    
    // Get department-wise breakdown
    $deptStmt = $conn->prepare("
        SELECT 
            e.department,
            COUNT(*) as total,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN a.status = 'present_late' THEN 1 ELSE 0 END) as present_late,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN a.status IS NULL THEN 1 ELSE 0 END) as no_record
        FROM employees e
        LEFT JOIN attendance a ON e.employee_code = a.employee_code AND a.date = ?
        WHERE e.status = 'active'
        GROUP BY e.department
        ORDER BY e.department
    ");
    
    $deptStmt->execute([$currentDate]);
    $departmentBreakdown = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent attendance activity (last 10 records)
    $recentStmt = $conn->prepare("
        SELECT 
            a.employee_code,
            e.first_name,
            e.last_name,
            a.status,
            a.check_in_time,
            a.reason,
            a.date
        FROM attendance a
        JOIN employees e ON a.employee_code = e.employee_code
        WHERE a.date = ?
        ORDER BY a.check_in_time DESC
        LIMIT 10
    ");
    
    $recentStmt->execute([$currentDate]);
    $recentActivity = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'date' => $currentDate,
        'summary' => $summary,
        'attendance_data' => $attendanceData,
        'department_breakdown' => $departmentBreakdown,
        'recent_activity' => $recentActivity
    ]);
    
} catch (PDOException $e) {
    error_log("Attendance overview DB error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Attendance overview error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred']);
}
?> 