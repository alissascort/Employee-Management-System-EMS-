<?php
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();
header("Content-Type: application/json");

// Check if user is logged in (admin or employee)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'employee'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';

try {
    $db = new Database();
    $conn = $db->connect();
    
    $currentDate = date('Y-m-d');
    
    // Get real attendance statistics for today
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
    
    // Get today's attendance activities
    $stmt = $conn->prepare("
        SELECT 
            a.employee_code,
            CONCAT(e.first_name, ' ', e.last_name) as employee_name,
            a.status,
            a.check_in_time,
            a.reason
        FROM attendance a
        JOIN employees e ON a.employee_code = e.employee_code
        WHERE a.date = ?
        ORDER BY a.check_in_time DESC
    ");
    $stmt->execute([$currentDate]);
    $todayActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate this month percentage (simplified calculation)
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN a.status IN ('present', 'present_late') THEN 1 ELSE 0 END) as present_days
        FROM attendance a
        JOIN employees e ON a.employee_code = e.employee_code
        WHERE e.status = 'active' 
        AND a.date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        AND a.date <= CURDATE()
    ");
    $stmt->execute();
    $monthlyData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $thisMonthPercentage = 0;
    if ($monthlyData['total_days'] > 0) {
        $thisMonthPercentage = round(($monthlyData['present_days'] / $monthlyData['total_days']) * 100);
    }
    
    // Get monthly trend data (last 7 days)
    $stmt = $conn->prepare("
        SELECT 
            a.date,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count
        FROM attendance a
        JOIN employees e ON a.employee_code = e.employee_code
        WHERE e.status = 'active'
        AND a.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY a.date
        ORDER BY a.date DESC
    ");
    $stmt->execute();
    $monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response = [
        'success' => true,
        'stats' => [
            'this_month_percentage' => $thisMonthPercentage,
            'present_today' => $attendanceStats['present'],
            'present_late_today' => $attendanceStats['present_late'],
            'late_today' => $attendanceStats['late'],
            'absent_today' => $attendanceStats['absent']
        ],
        'today_activities' => $todayActivities,
        'monthly_trend' => $monthlyTrend
    ];
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $response = ['success' => false, 'message' => 'Database error occurred'];
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $response = ['success' => false, 'message' => 'An error occurred'];
}

echo json_encode($response);
?>