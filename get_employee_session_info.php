<?php
// Set session cookie parameters before starting session
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Debug: Log session in dashboard
error_log('SESSION IN DASHBOARD: ' . print_r($_SESSION, true));

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'employee') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $db = new Database();
    $conn = $db->connect();

    $stmt = $conn->prepare("SELECT e.employee_id, e.employee_code, e.email, e.first_name, e.last_name, e.department, e.position, e.hire_date, e.phone, e.status, e.role, e.profile_photo AS employee_profile_photo, s.address, s.country, s.state, s.city, s.date_of_birth, s.registration_date, s.profile_photo AS staff_profile_photo
        FROM employees e
        LEFT JOIN staff_profiles s ON e.employee_id = s.employee_id
        WHERE e.employee_id = ?");
    $stmt->execute([$user_id]);

    if ($stmt->rowCount() > 0) {
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        // Prefer staff profile photo if available
        $profile_photo = $profile['staff_profile_photo'] ? $profile['staff_profile_photo'] : $profile['employee_profile_photo'];
        echo json_encode([
            'success' => true,
            'full_name' => $profile['first_name'] . ' ' . $profile['last_name'],
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name'],
            'employee_id' => $profile['employee_id'],
            'employee_code' => $profile['employee_code'],
            'position' => $profile['position'],
            'department' => $profile['department'],
            'email' => $profile['email'],
            'hire_date' => $profile['hire_date'],
            'phone' => $profile['phone'],
            'status' => $profile['status'],
            'role' => $profile['role'],
            'profile_photo' => $profile_photo,
            'address' => $profile['address'],
            'country' => $profile['country'],
            'state' => $profile['state'],
            'city' => $profile['city'],
            'date_of_birth' => $profile['date_of_birth'],
            'registration_date' => $profile['registration_date'],
            'user_type' => 'employee'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Profile not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?> 