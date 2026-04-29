<?php
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();
header("Content-Type: application/json");

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';

$response = ['success' => false, 'message' => 'An error occurred'];

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Get all departments with employee counts
    $stmt = $conn->prepare("
        SELECT 
            d.department_id,
            d.name as department_name,
            d.status,
            d.description,
            d.budget,
            d.created_at,
            COUNT(e.employee_id) as employee_count
        FROM departments d
        LEFT JOIN employees e ON d.name = e.department
        GROUP BY d.department_id, d.name, d.status, d.description, d.budget, d.created_at
        ORDER BY d.created_at DESC
    ");
    $stmt->execute();
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return empty array if no departments exist
    if (empty($departments)) {
        $departments = [];
    }
    
    $response = [
        'success' => true,
        'departments' => $departments
    ];

} catch (PDOException $e) {
    error_log("Database Error in get_all_departments.php: " . $e->getMessage());
    $response['message'] = 'Database error occurred: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Error in get_all_departments.php: " . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

echo json_encode($response);
?>
