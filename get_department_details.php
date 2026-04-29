<?php
header('Content-Type: application/json');
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

$deptName = $_GET['name'] ?? '';
if (!$deptName) {
    echo json_encode(['success' => false, 'message' => 'No department name provided.']);
    exit;
}

require_once __DIR__ . '/db_connect.php';
$db = (new Database())->connect();
if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

try {
    // 1. Get department info
    $stmt = $db->prepare("SELECT name, budget FROM departments WHERE name = ?");
    $stmt->execute([$deptName]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$department) {
        echo json_encode(['success' => false, 'message' => 'Department not found.']);
        exit;
    }

    // 2. Get employees in department
    $stmt = $db->prepare("SELECT firstname, lastname, position FROM staff_profiles WHERE department = ?");
    $stmt->execute([$deptName]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Get teams in department
    $stmt = $db->prepare("SELECT name FROM teams WHERE department = ?");
    $stmt->execute([$deptName]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Get activities for department
    $stmt = $db->prepare("SELECT id, task, status, due_date, completed_date, reason_for_delay, admin_extension 
                          FROM department_activities WHERE department = ?");
    $stmt->execute([$deptName]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'department' => $department,
        'employees' => $employees,
        'teams' => $teams,
        'activities' => $activities
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
