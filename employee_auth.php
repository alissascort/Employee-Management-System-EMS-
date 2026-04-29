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

$input = json_decode(file_get_contents('php://input'), true);
$response = ['success' => false, 'message' => 'Invalid request'];

try {
    $db = new Database();
    $conn = $db->connect();
    
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'login':
            $employeeCode = $input['employee_code'] ?? '';
            $password = $input['password'] ?? '';

            if (empty($employeeCode) || empty($password)) {
                $response['message'] = 'Employee code and password are required';
                echo json_encode($response);
                exit;
            }

            // Validate employee code format consistently
            // Format: YYYY/EMP/XXXX (4 digits year, EMP, 4 digits)
            $employeeCodePattern = '/^[0-9]{4}\/EMP\/[0-9]{4}$/';
            if (!preg_match($employeeCodePattern, $employeeCode)) {
                $response['message'] = 'Invalid employee code format. Expected format: YYYY/EMP/XXXX (e.g., 2025/EMP/0001)';
                echo json_encode($response);
                exit;
            }
            
            // For now, use a simple authentication (you can enhance this with proper password hashing)
            $stmt = $conn->prepare("SELECT employee_id, employee_code, first_name, last_name, email, department, position FROM employees WHERE employee_code = ? AND status = 'active'");
            $stmt->execute([$employeeCode]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($employee) {
                // Clear any existing session and regenerate ID for security
                session_regenerate_id(true);
                
                // Clear all session variables
                $_SESSION = array();
                
                // Set session data
                $_SESSION['user_id'] = $employee['employee_id'];
                $_SESSION['employee_code'] = $employee['employee_code'];
                $_SESSION['user_type'] = 'employee';
                $_SESSION['employee_name'] = $employee['first_name'] . ' ' . $employee['last_name'];
                
                $response = [
                    'success' => true,
                    'message' => 'Login successful',
                    'employee' => [
                        'employee_id' => $employee['employee_id'],
                        'employee_code' => $employee['employee_code'],
                        'name' => $employee['first_name'] . ' ' . $employee['last_name'],
                        'department' => $employee['department'],
                        'position' => $employee['position']
                    ]
                ];
            } else {
                $response['message'] = 'Invalid employee code or employee not found';
            }
            break;
            
        case 'logout':
            session_destroy();
            $response = ['success' => true, 'message' => 'Logged out successfully'];
            break;
            
        case 'check_session':
            if (isset($_SESSION['user_id']) && $_SESSION['user_type'] === 'employee') {
                $response = [
                    'success' => true,
                    'logged_in' => true,
                    'employee_code' => $_SESSION['employee_code'],
                    'employee_name' => $_SESSION['employee_name']
                ];
            } else {
                $response = [
                    'success' => true,
                    'logged_in' => false
                ];
            }
            break;
            
        default:
            $response['message'] = 'Invalid action';
    }
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $response['message'] = 'Database error occurred';
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $response['message'] = 'An error occurred';
}

echo json_encode($response);
?> 