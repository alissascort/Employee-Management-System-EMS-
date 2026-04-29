<?php
header("Content-Type: application/json");
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
require_once 'db_connect.php';

// ========== ADD THE LOGGING FUNCTION HERE ==========
function logSystemActivity($level, $type, $message, $userId = null, $ipAddress = null) {
    global $conn;
    
    if ($ipAddress === null) {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    
    try {
        // Map the type to appropriate categories
        $logType = mapLogType($type);
        
        $stmt = $conn->prepare("INSERT INTO system_logs 
                            (log_level, log_type, type, message, timestamp, ip_address, user_id) 
                            VALUES (?, ?, ?, ?, NOW(), ?, ?)");
        
        $stmt->execute([$level, $logType, $type, $message, $ipAddress, $userId]);
        return true;
    } catch (PDOException $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}

function mapLogType($activityType) {
    $typeMapping = [
        // Authentication events
        'login_success' => 'authentication',
        'login_failed' => 'authentication',
        'logout' => 'authentication',
        'password_change' => 'authentication',
        'password_reset' => 'authentication',
        
        // Security events
        'unauthorized_access' => 'security',
        'brute_force_attempt' => 'security',
        'suspicious_activity' => 'security',
        
        // System operations
        'system_startup' => 'system',
        'system_shutdown' => 'system',
        
        // Database operations
        'db_connection' => 'database',
        'db_error' => 'database',
        
        // User management
        'user_created' => 'user_management',
        'user_updated' => 'user_management',
        'user_deleted' => 'user_management'
    ];
    
    return $typeMapping[$activityType] ?? 'general';
}
// ========== END LOGGING FUNCTION ==========

$input = json_decode(file_get_contents('php://input'), true);
$response = ['success' => false, 'message' => ''];

// ========== EMPLOYEE AUTHENTICATION LOGIC ==========
// Check if this is an employee login request
if (isset($input['employee_code']) && isset($input['password']) && (!isset($input['user_type']) || $input['user_type'] === 'employee')) {
    
    // --- Rate limiting: block after 5 failed attempts in 10 minutes ---
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['first_attempt_time'] = time();
    }
    if (
        $_SESSION['login_attempts'] >= 5 &&
        (time() - $_SESSION['first_attempt_time']) < 600
    ) {
        $response['message'] = 'Too many failed login attempts. Please try again later.';
        echo json_encode($response);
        exit;
    }
    if ((time() - $_SESSION['first_attempt_time']) >= 600) {
        // Reset after 10 minutes
        $_SESSION['login_attempts'] = 0;
        $_SESSION['first_attempt_time'] = time();
    }
    // --- End rate limiting ---

    $employeeCode = trim($input['employee_code'] ?? '');
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
    
    try {
        $db = new Database();
        $conn = $db->connect();
        
        // Get employee with password hash - USING YOUR ACTUAL COLUMN NAMES
        $stmt = $conn->prepare("
            SELECT 
                employee_id, 
                employee_code, 
                first_name, 
                last_name, 
                email, 
                department, 
                position, 
                password_hash,
                status,
                failed_attempts,
                last_failed_login
            FROM employees 
            WHERE employee_code = ? 
            AND status IN ('active', 'locked')
        ");
        $stmt->execute([$employeeCode]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
         if ($employee) {
            // ========== ADD SUBSCRIPTION CHECK FOR EMPLOYEES ==========
            $subStmt = $conn->prepare("SELECT * FROM subscriptions WHERE email = :email LIMIT 1");
            $subStmt->bindParam(':email', $employee['email']);
            $subStmt->execute();

            if ($subStmt->rowCount() === 0) {
                logSystemActivity('WARNING', 'login_failed', "No subscription found for employee email: " . $employee['email'], null, $_SERVER['REMOTE_ADDR']);
                $response['message'] = 'Access denied. Please subscribe to cybersecurity updates first.';
                $response['redirect'] = 'FSM.ESM.FRONT.1.html'; // Add redirect
                echo json_encode($response);
                exit;
            }

            // Check if subscription confirmed today
            $subRow = $subStmt->fetch(PDO::FETCH_ASSOC);
            $lastConfirmed = $subRow['last_confirmed_at'] ?? null;

            if ($lastConfirmed === null) {
                logSystemActivity('WARNING', 'login_failed', "Unconfirmed subscription for employee email: " . $employee['email'], null, $_SERVER['REMOTE_ADDR']);
                $response['message'] = 'Please confirm your subscription via the link sent to your email before logging in.';
                echo json_encode($response);
                exit;
            }

            $today = new DateTime('today');
            $confirmedDate = new DateTime($lastConfirmed);

            // Employees require daily confirmation
            if ($confirmedDate < $today) {
                logSystemActivity('WARNING', 'login_failed', "Subscription not confirmed today for employee email: " . $employee['email'], null, $_SERVER['REMOTE_ADDR']);
                $response['message'] = 'Please confirm your subscription today via the email link before logging in.';
                $response['redirect'] = 'FSM.ESM.FRONT.1.html'; // Add redirect
                echo json_encode($response);
                exit;
            }
            // ========== END SUBSCRIPTION CHECK ==========
        
        if ($employee) {
            // Check if account is locked (using last_failed_login as lock indicator)
            if ($employee['last_failed_login'] && strtotime($employee['last_failed_login']) > time() - 1800) {
                $response['message'] = 'Account temporarily locked due to too many failed attempts. Please try again later.';
                echo json_encode($response);
                exit;
            }
            
            // Check if account is active
            if ($employee['status'] !== 'active') {
                $response['message'] = 'Your account is not active. Please contact HR.';
                echo json_encode($response);
                exit;
            }
            
            // Verify password
            if (password_verify($password, $employee['password_hash'])) {
                // Password correct - reset failed attempts
                $resetStmt = $conn->prepare("
                    UPDATE employees 
                    SET failed_attempts = 0, 
                        last_failed_login = NULL
                    WHERE employee_id = ?
                ");
                $resetStmt->execute([$employee['employee_id']]);
                
                // Clear rate limiting
                unset($_SESSION['login_attempts']);
                unset($_SESSION['first_attempt_time']);
                
                // Regenerate session ID for security
                session_regenerate_id(true);
                
                // Clear all session variables
                $_SESSION = array();
                
                // Set session data
                $_SESSION['user_id'] = $employee['employee_id'];
                $_SESSION['employee_code'] = $employee['employee_code'];
                $_SESSION['user_type'] = 'employee';
                $_SESSION['employee_name'] = $employee['first_name'] . ' ' . $employee['last_name'];
                $_SESSION['login_time'] = time();
                $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                
                // Log successful login
                logSystemActivity('INFO', 'login_success', "Employee {$employeeCode} logged in successfully", $employee['employee_id'], $_SERVER['REMOTE_ADDR']);
                
                $response = [
                    'success' => true,
                    'message' => 'Login successful',
                    'redirect' => 'FSM.ESM.EMPLOYEE.dashboard.html',
                    'employee' => [
                        'employee_id' => $employee['employee_id'],
                        'employee_code' => $employee['employee_code'],
                        'name' => $employee['first_name'] . ' ' . $employee['last_name'],
                        'department' => $employee['department'],
                        'position' => $employee['position'],
                        'email' => $employee['email']
                    ]
                ];
            } else {
                // Password incorrect - increment failed attempts
                $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                
                // Update database failed attempts - USING YOUR COLUMN NAMES
                $newAttempts = ($employee['failed_attempts'] ?? 0) + 1;
                $lockTime = null;
                
                // Lock account after 5 failed attempts for 30 minutes
                if ($newAttempts >= 5) {
                    $lockTime = date('Y-m-d H:i:s'); // Set current time as lock time
                }
                
                $failStmt = $conn->prepare("
                    UPDATE employees 
                    SET failed_attempts = ?,
                        last_failed_login = ?
                    WHERE employee_id = ?
                ");
                $failStmt->execute([$newAttempts, $lockTime, $employee['employee_id']]);
                
                // Log failed attempt
                logSystemActivity('WARNING', 'login_failed', "Failed login attempt for employee {$employeeCode}", $employee['employee_id'], $_SERVER['REMOTE_ADDR']);
                
                // Generic error message for security
                $response['message'] = 'Invalid employee code or password';
            }
        } else {
            // Employee not found - increment rate limiting
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            
            // Log failed attempt
            logSystemActivity('WARNING', 'login_failed', "Employee not found: {$employeeCode}", null, $_SERVER['REMOTE_ADDR']);
            
            $response['message'] = 'Invalid employee code or password';
        }
    }
          } catch (PDOException $e) {
        error_log("Database Error in employee login: " . $e->getMessage());
        logSystemActivity('ERROR', 'db_error', "Database error in employee login: " . $e->getMessage(), null, $_SERVER['REMOTE_ADDR']);
        $response['message'] = 'System error occurred. Please try again.';
    } catch (Exception $e) {
        error_log("Error in employee login: " . $e->getMessage());
        logSystemActivity('ERROR', 'system_error', "General error in employee login: " . $e->getMessage(), null, $_SERVER['REMOTE_ADDR']);
        $response['message'] = 'An error occurred. Please try again.';
    }
    
    echo json_encode($response);
    exit;
}
   
// ========== END EMPLOYEE AUTHENTICATION LOGIC ==========

// ========== ORIGINAL FSM.ESM.FRONT.1.PHP LOGIC ==========
// --- Rate limiting: block after 5 failed attempts in 10 minutes ---
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['first_attempt_time'] = time();
}
if (
    $_SESSION['login_attempts'] >= 5 &&
    (time() - $_SESSION['first_attempt_time']) < 600
) {
    $response['message'] = 'Too many failed login attempts. Please try again later.';
    echo json_encode($response);
    exit;
}
if ((time() - $_SESSION['first_attempt_time']) >= 600) {
    // Reset after 10 minutes
    $_SESSION['login_attempts'] = 0;
    $_SESSION['first_attempt_time'] = time();
}
// --- End rate limiting ---

if (!isset($input['email'], $input['password'], $input['user_type'])) {
    $response['message'] = 'Missing required fields';
    echo json_encode($response);
    exit;
}

$email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
$password = $input['password'];
$userType = $input['user_type'];

// --- Stricter input validation ---
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    logSystemActivity('WARNING', 'login_failed', "Invalid email format: $email", null, $_SERVER['REMOTE_ADDR']);
    $response['message'] = 'Invalid login credentials';
    echo json_encode($response);
    exit;
}
if (strlen($password) < 6) {
    logSystemActivity('WARNING', 'login_failed', "Password too short for email: $email", null, $_SERVER['REMOTE_ADDR']);
    $response['message'] = 'Invalid login credentials';
    echo json_encode($response);
    exit;
}
// --- End stricter input validation ---

try {
    $db = new Database();
    $conn = $db->connect();

    // Check subscription existence
    $subStmt = $conn->prepare("SELECT * FROM subscriptions WHERE email = :email LIMIT 1");
    $subStmt->bindParam(':email', $email);
    $subStmt->execute();

    if ($subStmt->rowCount() === 0) {
        logSystemActivity('WARNING', 'login_failed', "No subscription found for email: $email", null, $_SERVER['REMOTE_ADDR']);
        $response['message'] = 'Access denied. Please subscribe to cybersecurity updates first.';
        $response['redirect'] = 'FSM.ESM.FRONT.1.html'; // Add redirect
        echo json_encode($response);
        exit;
    }

    // New: Check if subscription confirmed today
    $subRow = $subStmt->fetch(PDO::FETCH_ASSOC);
    $lastConfirmed = $subRow['last_confirmed_at'] ?? null;

    if ($lastConfirmed === null) {
        logSystemActivity('WARNING', 'login_failed', "Unconfirmed subscription for email: $email", null, $_SERVER['REMOTE_ADDR']);
        $response['message'] = 'Please confirm your subscription via the link sent to your email before logging in.';
        $response['redirect'] = 'FSM.ESM.FRONT.1.html'; // Add redirect
        echo json_encode($response);
        exit;
    }

    $today = new DateTime('today');
    $confirmedDate = new DateTime($lastConfirmed);

    // For CSO users, be more lenient with subscription confirmation
    if ($userType === 'cso') {
        // CSO users can login if they have a confirmed subscription (any date)
        if ($confirmedDate < $today) {
            // Update the last_confirmed_at to today for CSO users
            $updateStmt = $conn->prepare("UPDATE subscriptions SET last_confirmed_at = NOW() WHERE email = :email");
            $updateStmt->bindParam(':email', $email);
            $updateStmt->execute();
        }
    } else {
        // For other users, require daily confirmation
        if ($confirmedDate < $today) {
            logSystemActivity('WARNING', 'login_failed', "Subscription not confirmed today for email: $email", null, $_SERVER['REMOTE_ADDR']);
            $response['message'] = 'Please confirm your subscription today via the email link before logging in.';
            $response['redirect'] = 'FSM.ESM.FRONT.1.html'; // Add redirect
            echo json_encode($response);
            exit;
        }
    }

    // Determine which table to use
    switch ($userType) {
        case 'admin':
            $table = 'admins';
            $idField = 'admin_id';
            $nameField = 'full_name';
            break;
        case 'employee':
            $table = 'employees';
            $idField = 'employee_id';
            $nameField = 'first_name';
            break;
        case 'cso':
            $table = 'csos';
            $idField = 'cso_id';
            $nameField = 'full_name';
            break;
        case 'hr':
            $table = 'hr';
            $idField = 'hr_id';
            $nameField = 'full_name';
            break;
        case 'Dept Manager':
            $table = 'dept_managers';
            $idField = 'manager_id';
            $nameField = 'full_name';
            $department = $input['department'];
            $stmt = $conn->prepare("SELECT * FROM {$table} WHERE email = :email AND department = :department LIMIT 1");
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':department', $department);
            $stmt->execute();
            if ($stmt->rowCount() === 0) {
                logSystemActivity('WARNING', 'login_failed', "Manager not found for department: $department, email: $email", null, $_SERVER['REMOTE_ADDR']);
                $response['message'] = 'Manager not found for this department';
                echo json_encode($response);
                exit;
            }
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
        default:
            logSystemActivity('WARNING', 'login_failed', "Invalid user type: $userType for email: $email", null, $_SERVER['REMOTE_ADDR']);
            $response['message'] = 'Invalid user type';
            echo json_encode($response);
            exit;
    }

    // For all except Dept Manager, check user existence
    if ($userType !== 'Dept Manager') {
        $stmt = $conn->prepare("SELECT * FROM {$table} WHERE email = :email LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            logSystemActivity('WARNING', 'login_failed', "User not found in $table for email: $email", null, $_SERVER['REMOTE_ADDR']);
            $_SESSION['login_attempts']++;
            $response['message'] = 'Invalid login credentials';
            echo json_encode($response);
            exit;
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // --- Account lockout check ---
        if (!empty($user['lockout_until']) && strtotime($user['lockout_until']) > time()) {
            logSystemActivity('WARNING', 'login_failed', "Account locked for email: $email", $user[$idField], $_SERVER['REMOTE_ADDR']);
            $response['message'] = 'Account locked due to too many failed login attempts. Please try again later.';
            echo json_encode($response);
            exit;
        }
        // --- End lockout check ---
    }

    // Check password hash
    if (!password_verify($password, $user['password_hash'])) {
        logSystemActivity('WARNING', 'login_failed', "Wrong password for email: $email", $user[$idField], $_SERVER['REMOTE_ADDR']);
        $_SESSION['login_attempts']++;
        
        // --- Increment failed_attempts and lock account if needed ---
        $failedAttempts = isset($user['failed_attempts']) ? (int)$user['failed_attempts'] + 1 : 1;
        $lockout = null;
        if ($failedAttempts >= 5) {
            $lockout = date('Y-m-d H:i:s', time() + 900); // 15 minutes lockout
            logSystemActivity('WARNING', 'account_locked', "Account locked for 15 minutes due to failed attempts: $email", $user[$idField], $_SERVER['REMOTE_ADDR']);
        }
        $updateLockStmt = $conn->prepare("UPDATE {$table} SET failed_attempts = :fa, lockout_until = :lu WHERE {$idField} = :id");
        $updateLockStmt->bindParam(':fa', $failedAttempts);
        $updateLockStmt->bindParam(':lu', $lockout);
        $updateLockStmt->bindParam(':id', $user[$idField]);
        $updateLockStmt->execute();
        // --- End increment/lockout ---
        
        $response['message'] = 'Invalid login credentials';
        echo json_encode($response);
        exit;
    }

    // --- Reset failed attempts and lockout on success ---
    $resetLockStmt = $conn->prepare("UPDATE {$table} SET failed_attempts = 0, lockout_until = NULL WHERE {$idField} = :id");
    $resetLockStmt->bindParam(':id', $user[$idField]);
    $resetLockStmt->execute();
    // --- End reset ---

    // --- Session security: regenerate session ID ---
    session_regenerate_id(true);
    // --- End session security ---

    // Update login time
    $updateStmt = $conn->prepare("UPDATE {$table} SET last_login = NOW() WHERE {$idField} = :id");
    $updateStmt->bindParam(':id', $user[$idField]);
    $updateStmt->execute();

    // Set session
    $_SESSION['user_id'] = $user[$idField];
    $_SESSION['user_type'] = $userType;
    $_SESSION['email'] = $user['email'];
    $_SESSION['department'] = $userType === 'Dept Manager' ? $user['department'] : null;

    if ($userType === 'employee') {
        $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
    } else {
        $_SESSION['full_name'] = $user[$nameField];
    }
    
    // ✅ Fix: mark CSO as logged in
    if ($userType === 'cso') {
    $_SESSION['cso_logged_in'] = true;
    }
    
    // ---------------------------
    // LOG EVERY SUCCESSFUL LOGIN
    // ---------------------------
    logSystemActivity(
    'INFO',                    // log level
    'authentication',          // log type
    "User {$_SESSION['email']} logged in successfully as {$_SESSION['user_type']}",
    $_SESSION['user_id'],      // user ID
    $_SERVER['REMOTE_ADDR']    // IP address
    );

    // Log successful login
    logSystemActivity('INFO', 'login_success', "User {$email} logged in successfully as {$userType}", $user[$idField], $_SERVER['REMOTE_ADDR']);

    // Success response
    $response['success'] = true;
    $response['message'] = 'Login successful';

    // Redirect path
    $response['redirect'] = match ($userType) {
        'admin' => 'FSM.ESM.2.html',
        'employee' => 'FSM.ESM.EMPLOYEE.dashboard.html',
        'cso' => 'CSO-dashboard.html',
        'hr' => 'FSM.ESM.HR.html',
        'Dept Manager' => 'FSM.ESM.DEPT_MANAGER.html',
        default => 'FSM.ESM.FRONT.1.html',
    };

} catch (PDOException $e) {
    logSystemActivity('ERROR', 'db_error', "Database error in login: " . $e->getMessage(), null, $_SERVER['REMOTE_ADDR']);
    error_log("Database Error in CSO login: " . $e->getMessage());
    error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("User Type: " . $userType . " Email: " . $email);
    $response['message'] = 'Database error occurred';
    echo json_encode($response);
    exit;
} catch (Exception $e) {
    logSystemActivity('ERROR', 'system_error', "General error in login: " . $e->getMessage(), null, $_SERVER['REMOTE_ADDR']);
    error_log("General Error in CSO login: " . $e->getMessage());
    error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("User Type: " . $userType . " Email: " . $email);
    $response['message'] = 'Database error occurred';
    echo json_encode($response);
    exit;
}

// Debug: Log session after login
error_log('SESSION AFTER LOGIN: ' . print_r($_SESSION, true));
echo json_encode($response);
?>