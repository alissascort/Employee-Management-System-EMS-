<?php
/**
 * Enhanced Authentication System
 * Improved with rate limiting, better security, and comprehensive logging
 */

// Set secure session cookie parameters
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => false // Set to true for HTTPS
]);
session_start();
require_once 'db_connect.php';

header("Content-Type: application/json");

// Security settings
$maxLoginAttempts = 5;
$lockoutDuration = 900; // 15 minutes
$sessionTimeout = 3600; // 1 hour

$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$db = Database::getInstance();
$conn = $db->getConnection();

// Rate limiting check
if (isRateLimited($ipAddress, $maxLoginAttempts, $lockoutDuration)) {
    echo json_encode([
        'success' => false,
        'message' => 'Too many login attempts. Please try again in 15 minutes.',
        'code' => 'RATE_LIMITED'
    ]);
    exit;
}

// Input validation
if (empty($email) || empty($password)) {
    logFailedAttempt($ipAddress, $email, 'Empty credentials');
    echo json_encode([
        'success' => false,
        'message' => 'Email and password are required',
        'code' => 'INVALID_INPUT'
    ]);
    exit;
}

// Sanitize email
$email = filter_var($email, FILTER_SANITIZE_EMAIL);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    logFailedAttempt($ipAddress, $email, 'Invalid email format');
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email format',
        'code' => 'INVALID_EMAIL'
    ]);
    exit;
}

// Security helper functions
function isRateLimited($ipAddress, $maxAttempts, $lockoutDuration) {
    global $conn;
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as attempts 
            FROM login_attempts 
            WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$ipAddress, $lockoutDuration]);
        $result = $stmt->fetch();
        return $result['attempts'] >= $maxAttempts;
    } catch (Exception $e) {
        error_log("Rate limiting check failed: " . $e->getMessage());
        return false; // Allow login if rate limiting fails
    }
}

function logFailedAttempt($ipAddress, $email, $reason) {
    global $conn;
    try {
        $stmt = $conn->prepare("
            INSERT INTO login_attempts (ip_address, email, reason, attempt_time) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$ipAddress, $email, $reason]);
    } catch (Exception $e) {
        error_log("Failed to log login attempt: " . $e->getMessage());
    }
}

function logSuccessfulLogin($userId, $userType, $ipAddress) {
    global $conn;
    try {
        $stmt = $conn->prepare("
            INSERT INTO login_logs (user_id, user_type, ip_address, login_time, status) 
            VALUES (?, ?, ?, NOW(), 'success')
        ");
        $stmt->execute([$userId, $userType, $ipAddress]);
    } catch (Exception $e) {
        error_log("Failed to log successful login: " . $e->getMessage());
    }
}

function clearFailedAttempts($ipAddress) {
    global $conn;
    try {
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ipAddress]);
    } catch (Exception $e) {
        error_log("Failed to clear login attempts: " . $e->getMessage());
    }
}

// Check all user tables in one query with enhanced security
$stmt = $conn->prepare("
    SELECT 'admin' as role, email, password_hash FROM admins WHERE email = ? AND status = 'active'
    UNION ALL
    SELECT 'employee' as role, email, password_hash FROM employees WHERE email = ? AND status = 'active'
    UNION ALL
    SELECT 'cso' as role, email, password_hash FROM csos WHERE email = ? AND status = 'active'
    UNION ALL
    SELECT 'hr' as role, email, password_hash FROM hr WHERE email = ? AND status = 'active'
    LIMIT 1
");
$stmt->execute([$email, $email, $email, $email]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user && password_verify($password, $user['password_hash'])) {
    // Clear failed attempts on successful login
    clearFailedAttempts($ipAddress);
    $forceChange = false;
    $windowExpired = false;
    $employeeCode = null;
    
    // Get user details based on role
    $userDetails = null;
    switch ($user['role']) {
        case 'admin':
            $stmt2 = $conn->prepare("SELECT admin_id, email, full_name FROM admins WHERE email = :email LIMIT 1");
            $stmt2->bindParam(':email', $email);
            $stmt2->execute();
            $userDetails = $stmt2->fetch(PDO::FETCH_ASSOC);
            break;
        case 'employee':
            $stmt2 = $conn->prepare("SELECT employee_id, email, first_name, last_name, department FROM employees WHERE email = :email LIMIT 1");
            $stmt2->bindParam(':email', $email);
            $stmt2->execute();
            $userDetails = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($userDetails && $userDetails['employee_id']) {
                $employeeCode = $userDetails['employee_id'];
                // Check for active temporary password window
                $stmt3 = $conn->prepare("SELECT * FROM password_recovery_requests WHERE employee_code = ? AND status = 'APPROVED' AND old_password_sent = 1 ORDER BY old_password_sent_at DESC LIMIT 1");
                $stmt3->execute([$employeeCode]);
                $recovery = $stmt3->fetch(PDO::FETCH_ASSOC);
                if ($recovery) {
                    $now = date('Y-m-d H:i:s');
                    if ($recovery['old_password_valid_until'] && $now <= $recovery['old_password_valid_until']) {
                        $forceChange = true;
                    } else if ($recovery['old_password_valid_until'] && $now > $recovery['old_password_valid_until']) {
                        $windowExpired = true;
                    }
                }
            }
            break;
        case 'cso':
            $stmt2 = $conn->prepare("SELECT cso_id, email, full_name FROM csos WHERE email = :email LIMIT 1");
            $stmt2->bindParam(':email', $email);
            $stmt2->execute();
            $userDetails = $stmt2->fetch(PDO::FETCH_ASSOC);
            break;
        case 'hr':
            $stmt2 = $conn->prepare("SELECT hr_id, email, full_name FROM hr WHERE email = :email LIMIT 1");
            $stmt2->bindParam(':email', $email);
            $stmt2->execute();
            $userDetails = $stmt2->fetch(PDO::FETCH_ASSOC);
            break;
    }
    
    if ($windowExpired) {
        echo json_encode([
            'success' => false,
            'message' => 'Temporary password window expired. Please request password recovery again.'
        ]);
        exit;
    }
    
    // Set secure session variables
    session_regenerate_id(true);
    
    // Clear all session variables
    $_SESSION = array();
    
    $_SESSION['user_id'] = $userDetails['admin_id'] ?? $userDetails['employee_id'] ?? $userDetails['cso_id'] ?? $userDetails['hr_id'];
    $_SESSION['user_type'] = $user['role'];
    $_SESSION['email'] = $userDetails['email'];
    $_SESSION['login_time'] = time();
    $_SESSION['ip_address'] = $ipAddress;
    $_SESSION['session_id'] = session_id();
    $_SESSION['expires_at'] = time() + $sessionTimeout;
    
    if ($user['role'] === 'employee') {
        $_SESSION['full_name'] = $userDetails['first_name'] . ' ' . $userDetails['last_name'];
        $_SESSION['department'] = $userDetails['department'];
    } else {
        $_SESSION['full_name'] = $userDetails['full_name'];
    }
    
    // Define role-based redirect path
    $redirectPath = '';
    switch ($user['role']) {
        case 'admin':
            $redirectPath = 'FSM.ESM.2.html';
            break;
        case 'employee':
            $redirectPath = 'FSM.ESM.EMPLOYEE.dashboard.html';
            break;
        case 'cso':
            $redirectPath = 'CSO-dashboard.html';
            break;
        case 'hr':
            $redirectPath = 'HR_Management_system.html';
            break;
    }
    
    // Log successful login
    logSuccessfulLogin($_SESSION['user_id'], $user['role'], $ipAddress);
    
    // Debug: Log session after login
    error_log('SESSION AFTER LOGIN (auth.php): ' . print_r($_SESSION, true));
    
    echo json_encode([
        'success' => true,
        'role' => $user['role'],
        'message' => 'Login successful',
        'redirect' => $redirectPath,
        'force_password_change' => $forceChange
    ]);
} else {
    // Log failed login attempt
    logFailedAttempt($ipAddress, $email, 'Invalid credentials');
    
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email or password',
        'code' => 'INVALID_CREDENTIALS'
    ]);
}
?>
