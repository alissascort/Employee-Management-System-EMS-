<?php
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
    
    // Get all employees and check which ones need staff profiles
    $stmt = $conn->prepare("
        SELECT 
            e.employee_id, e.employee_code, e.first_name, e.last_name, e.email,
            e.department, e.position, e.hire_date, e.phone, e.status, e.role, e.profile_photo,
            CASE WHEN sp.id IS NULL THEN 'No Staff Profile' ELSE 'Complete' END as profile_status
        FROM employees e
        LEFT JOIN staff_profiles sp ON e.employee_id = sp.employee_id
        ORDER BY e.employee_id DESC
    ");
    $stmt->execute();
    $incompleteEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process each employee to identify status
    $processedEmployees = [];
    $totalIncomplete = 0;
    $completedToday = 0;
    
    foreach ($incompleteEmployees as $employee) {
        $missingFields = [];
        
        // Check for missing primary information
        if (empty($employee['first_name'])) $missingFields[] = 'First Name';
        if (empty($employee['last_name'])) $missingFields[] = 'Last Name';
        if (empty($employee['email'])) $missingFields[] = 'Email';
        if (empty($employee['department'])) $missingFields[] = 'Department';
        if (empty($employee['position'])) $missingFields[] = 'Position';
        if (empty($employee['hire_date'])) $missingFields[] = 'Hire Date';
        if (empty($employee['phone'])) $missingFields[] = 'Phone';
        if (empty($employee['profile_photo'])) $missingFields[] = 'Profile Photo';
        
        // Check if staff profile is missing
        if ($employee['profile_status'] === 'No Staff Profile') {
            $missingFields[] = 'Staff Profile';
        }
        
        $employee['missing_fields'] = $missingFields;
        $employee['completion_percentage'] = $employee['profile_status'] === 'Complete' ? 100 : 
            round(((9 - count($missingFields)) / 9) * 100);
        
        $processedEmployees[] = $employee;
        
        // Count incomplete records
        if ($employee['profile_status'] === 'No Staff Profile' || count($missingFields) > 0) {
            $totalIncomplete++;
        }
    }
    
    $response = [
        'success' => true,
        'incomplete_records' => $processedEmployees,
        'total_incomplete' => $totalIncomplete,
        'completed_today' => $completedToday
    ];

} catch (PDOException $e) {
    error_log("Database Error in get_incomplete_employee_records.php: " . $e->getMessage());
    $response['message'] = 'Database error occurred: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Error in get_incomplete_employee_records.php: " . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

echo json_encode($response);
?> 