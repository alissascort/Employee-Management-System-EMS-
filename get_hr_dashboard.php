<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
session_start();

// Only allow HR
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'hr') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();

    // Total employees
    $stmt = $conn->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'");
    $totalEmployees = (int)$stmt->fetchColumn();

    // Pending leaves
    $stmt = $conn->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'");
    $pendingLeaves = (int)$stmt->fetchColumn();

    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(*) FROM attendance WHERE date = :today AND status IN ('late', 'present_late')");
    $stmt->bindParam(':today', $today);
    $stmt->execute();
    $lateEmployees = (int)$stmt->fetchColumn();

    // Overdue trainings (example: trainings with due_date < today and not completed)
    $stmt = $conn->prepare("SELECT COUNT(*) FROM trainings WHERE due_date < :today AND status != 'Completed'");
    $stmt->bindParam(':today', $today);
    $stmt->execute();
    $overdueTrainings = (int)$stmt->fetchColumn();

    // Attendance stats for the last 7 days
    $attendanceStats = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stmt = $conn->prepare("SELECT COUNT(*) FROM attendance WHERE date = :date");
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        $attendanceStats[] = [
            'date' => $date,
            'count' => (int)$stmt->fetchColumn()
        ];
    }

    // Leave stats for chart (Approved, Pending, Rejected)
    $leaveStats = [
        'Approved' => 0,
        'Pending' => 0,
        'Rejected' => 0
    ];
    $stmt = $conn->query("SELECT status, COUNT(*) as count FROM leave_requests GROUP BY status");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = ucfirst(strtolower($row['status']));
        if (isset($leaveStats[$status])) {
            $leaveStats[$status] = (int)$row['count'];
        }
    }

    // Recent activities (example: last 5 leave requests, new employees, etc.)
    $activities = [];
    $stmt = $conn->query("SELECT CONCAT('New employee onboarded: ', first_name, ' ', last_name) AS title, 'fas fa-user-plus' AS icon, created_at AS time FROM employees ORDER BY created_at DESC LIMIT 2");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $activities[] = [
            'icon' => $row['icon'],
            'title' => $row['title'],
            'time' => $row['time']
        ];
    }
    
    $stmt = $conn->query("
        SELECT CONCAT('Leave request ', lr.status, ' for ', e.first_name, ' ', e.last_name) AS title, 
               'fas fa-calendar-check' AS icon, 
               lr.created_at AS time 
        FROM leave_requests lr 
        LEFT JOIN employees e ON lr.employee_id = e.employee_id 
        ORDER BY lr.created_at DESC 
        LIMIT 3
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $activities[] = [
            'icon' => $row['icon'],
            'title' => $row['title'],
            'time' => $row['time']
        ];
    }

    echo json_encode([
        'success' => true,
        'stats' => [
            'totalEmployees' => $totalEmployees,
            'pendingLeaves' => $pendingLeaves,
            'lateEmployees' => $lateEmployees,
            'overdueTrainings' => $overdueTrainings
        ],
        'attendanceStats' => $attendanceStats,
        'leaveStats' => $leaveStats,
        'activities' => $activities
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
