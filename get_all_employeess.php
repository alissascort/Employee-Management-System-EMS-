<?php
session_start();
header("Content-Type: application/json");

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'hr') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';

$response = ['success' => false, 'message' => 'An error occurred'];

try {
    $db = new Database();
    $conn = $db->connect();

    $stmt = $conn->prepare("
        SELECT 
            sp.id as staff_profile_id,
            sp.employee_id,
            sp.employee_code, 
            sp.firstname, sp.lastname, sp.email,
            sp.department, sp.role,
            sp.address, sp.country, sp.state, sp.city, 
            sp.date_of_birth, sp.registration_date, sp.profile_photo, sp.status
        FROM staff_profiles sp
        ORDER BY sp.registration_date DESC
    ");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Log the first employee data
    if (!empty($employees)) {
        error_log('First employee data: ' . print_r($employees[0], true));
    }

    $response = [
        'success' => true,
        'employees' => $employees
    ];

} catch (PDOException $e) {
    error_log("Database Error in get_all_employees.php: " . $e->getMessage());
    $response['message'] = 'Database error occurred: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Error in get_all_employees.php: " . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

echo json_encode($response);
?>
