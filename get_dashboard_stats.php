<?php
// api/getDashboardStats.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

$database = new Database();
$db = $database->getConnection();

$stats = [];

// Total employees
$query = "SELECT COUNT(*) as total FROM employees WHERE status = 'active'";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['total_employees'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Pending leaves
$query = "SELECT COUNT(*) as total FROM leave_requests WHERE status = 'PENDING'";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['pending_leaves'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Present today
$query = "SELECT COUNT(*) as total FROM attendance WHERE date = CURDATE() AND status = 'present'";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['present_today'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Present but late today
$query = "SELECT COUNT(*) as total FROM attendance WHERE date = CURDATE() AND status = 'present_late'";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['present_late_today'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Late employees today
$query = "SELECT COUNT(*) as total FROM attendance WHERE date = CURDATE() AND status = 'late'";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['late_employees'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Absent today
$query = "SELECT COUNT(*) as total FROM attendance WHERE date = CURDATE() AND status = 'absent'";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['absent_today'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Overdue trainings
$query = "SELECT COUNT(*) as total 
          FROM training_assignments ta 
          JOIN trainings t ON ta.training_id = t.id 
          WHERE ta.status = 'assigned' AND t.deadline < CURDATE()";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['overdue_trainings'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

http_response_code(200);
echo json_encode($stats);
?>
