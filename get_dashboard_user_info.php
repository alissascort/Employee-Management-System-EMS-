<?php
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Set session cookie parameters before starting session
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
header("Content-Type: application/json");
require_once 'db_connect.php';

// Debug: Log session
error_log('DASHBOARD USER INFO: ' . print_r($_SESSION, true));

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

try {
    $db = new Database();
    $conn = $db->connect();
    
    $user_data = null;
    
    switch ($user_type) {
        case 'employee':
            // Employee uses employee_code authentication
            if (!isset($_SESSION['employee_code'])) {
                echo json_encode(['success' => false, 'message' => 'Employee code not found in session']);
                exit;
            }
            
            $stmt = $conn->prepare("SELECT employee_id, employee_code, email, first_name, last_name, department, position, hire_date, phone, status, role, profile_photo FROM employees WHERE employee_id = ? AND employee_code = ?");
            $stmt->execute([$user_id, $_SESSION['employee_code']]);
            
            if ($stmt->rowCount() > 0) {
                $profile = $stmt->fetch(PDO::FETCH_ASSOC);
                $user_data = [
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
                    'profile_photo' => $profile['profile_photo'],
                    'user_type' => 'employee',
                    'auth_method' => 'employee_code',
                    'dashboard_source' => 'employee_dashboard'
                ];
            }
            break;
            
        case 'admin':
            // Admin uses email authentication
            if (!isset($_SESSION['email'])) {
                echo json_encode(['success' => false, 'message' => 'Email not found in session']);
                exit;
            }
            
            $stmt = $conn->prepare("SELECT admin_id, email, full_name, role, profile_photo FROM admins WHERE admin_id = ? AND email = ?");
            $stmt->execute([$user_id, $_SESSION['email']]);
            
            if ($stmt->rowCount() > 0) {
                $profile = $stmt->fetch(PDO::FETCH_ASSOC);
                $user_data = [
                    'success' => true,
                    'full_name' => $profile['full_name'],
                    'email' => $profile['email'],
                    'role' => $profile['role'],
                    'profile_photo' => $profile['profile_photo'],
                    'user_type' => 'admin',
                    'auth_method' => 'email',
                    'dashboard_source' => 'admin_dashboard'
                ];
            }
            break;
            
        case 'cso':
            // CSO uses email authentication
            if (!isset($_SESSION['email'])) {
                echo json_encode(['success' => false, 'message' => 'Email not found in session']);
                exit;
            }
            
            $stmt = $conn->prepare("SELECT cso_id, email, full_name, role, profile_photo FROM cso WHERE cso_id = ? AND email = ?");
            $stmt->execute([$user_id, $_SESSION['email']]);
            
            if ($stmt->rowCount() > 0) {
                $profile = $stmt->fetch(PDO::FETCH_ASSOC);
                $user_data = [
                    'success' => true,
                    'full_name' => $profile['full_name'],
                    'email' => $profile['email'],
                    'role' => $profile['role'],
                    'profile_photo' => $profile['profile_photo'],
                    'user_type' => 'cso',
                    'auth_method' => 'email',
                    'dashboard_source' => 'cso_dashboard'
                ];
            }
            break;
            
        case 'hr':
            // HR uses email authentication
            if (!isset($_SESSION['email'])) {
                echo json_encode(['success' => false, 'message' => 'Email not found in session']);
                exit;
            }
            
            $stmt = $conn->prepare("SELECT hr_id, email, full_name, role, profile_photo FROM hr WHERE hr_id = ? AND email = ?");
            $stmt->execute([$user_id, $_SESSION['email']]);
            
            if ($stmt->rowCount() > 0) {
                $profile = $stmt->fetch(PDO::FETCH_ASSOC);
                $user_data = [
                    'success' => true,
                    'full_name' => $profile['full_name'],
                    'email' => $profile['email'],
                    'role' => $profile['role'],
                    'profile_photo' => $profile['profile_photo'],
                    'user_type' => 'hr',
                    'auth_method' => 'email',
                    'dashboard_source' => 'hr_dashboard'
                ];
            }
            break;
            
        case 'dept_manager':
            // Department Manager uses email authentication
            if (!isset($_SESSION['email'])) {
                echo json_encode(['success' => false, 'message' => 'Email not found in session']);
                exit;
            }
            
            $stmt = $conn->prepare("SELECT manager_id, email, full_name, department, role, profile_photo FROM dept_managers WHERE manager_id = ? AND email = ?");
            $stmt->execute([$user_id, $_SESSION['email']]);
            
            if ($stmt->rowCount() > 0) {
                $profile = $stmt->fetch(PDO::FETCH_ASSOC);
                $user_data = [
                    'success' => true,
                    'full_name' => $profile['full_name'],
                    'email' => $profile['email'],
                    'department' => $profile['department'],
                    'role' => $profile['role'],
                    'profile_photo' => $profile['profile_photo'],
                    'user_type' => 'dept_manager',
                    'auth_method' => 'email',
                    'dashboard_source' => 'dept_manager_dashboard'
                ];
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid user type']);
            exit;
    }
    
    if ($user_data) {
        // Set default profile photo if none exists
        if (empty($user_data['profile_photo'])) {
            $user_data['profile_photo'] = 'Parrot.JPG';
        }
        
        // Log successful identification
        error_log('User identified: ' . $user_data['user_type'] . ' - ' . $user_data['full_name'] . ' via ' . $user_data['auth_method']);
        
        echo json_encode($user_data);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found or authentication mismatch']);
    }
    
} catch (PDOException $e) {
    error_log('Database error in dashboard user info: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?> 