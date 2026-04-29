<?php
header('Content-Type: application/json');
session_start();

// Database connection with error handling
try {
    $db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error. Please try again later.']);
    exit;
}

// Enhanced rate limiting function
function checkRateLimit($db, $employeeCode, $ipAddress) {
    // Check hourly attempts
    $stmt = $db->prepare("SELECT COUNT(*) FROM password_recovery_requests 
                         WHERE employee_code = ? AND request_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$employeeCode]);
    $count = $stmt->fetchColumn();
    
    if ($count >= 3) {
        return false;
    }
    
    // Check IP-based rate limiting
    $stmt = $db->prepare("SELECT COUNT(*) FROM recovery_attempt_log 
                         WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$ipAddress]);
    $ipCount = $stmt->fetchColumn();
    
    return $ipCount < 10; // Allow max 10 attempts per hour per IP
}

// Log recovery attempt
function logRecoveryAttempt($db, $employeeCode, $ipAddress, $success, $reason = null) {
    $stmt = $db->prepare("INSERT INTO recovery_attempt_log (employee_code, ip_address, success, reason) VALUES (?, ?, ?, ?)");
    
    // Convert boolean to integer (1 for true, 0 for false)
    $successValue = $success ? 1 : 0;
    $stmt->execute([$employeeCode, $ipAddress, $success, $reason]);
}

// Check if password is expired
function isPasswordExpired($db, $employeeCode) {
    $stmt = $db->prepare("SELECT password_expiry_date FROM staff_profiles WHERE employee_code = ?");
    $stmt->execute([$employeeCode]);
    $expiryDate = $stmt->fetchColumn();
    
    if (!$expiryDate) {
        return false; // No expiry date set
    }
    
    return strtotime($expiryDate) < time();
}

// Generate secure temporary password
function generateSecurePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// Update password and set expiry
function updatePassword($db, $employeeCode, $newPassword) {
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $expiryDate = date('Y-m-d', strtotime('+90 days')); // 90 days expiry
    
    $db->beginTransaction();
    
    try {
        // Save to password history
        $stmt = $db->prepare("INSERT INTO password_history (employee_code, password_hash) VALUES (?, ?)");
        $stmt->execute([$employeeCode, $passwordHash]);
        
        // Update current password
        $stmt = $db->prepare("UPDATE staff_profiles SET password_hash = ?, password_expiry_date = ?, last_password_change = NOW(), password_change_required = 0 WHERE employee_code = ?");
        $stmt->execute([$passwordHash, $expiryDate, $employeeCode]);
        
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Password update error: " . $e->getMessage());
        return false;
    }
}

// Handle high-security verification for old password requests
function validateHighSecurityRequest($db, $employeeData) {
    $employeeCode = $employeeData['employee_code'];
    
    // Check if recent old password request exists (max 1 per month)
    $stmt = $db->prepare("SELECT COUNT(*) FROM password_recovery_requests 
                         WHERE employee_code = ? AND reason = 'OLD_PASSWORD' 
                         AND request_time > DATE_SUB(NOW(), INTERVAL 1 MONTH)");
    $stmt->execute([$employeeCode]);
    $recentRequests = $stmt->fetchColumn();
    
    if ($recentRequests > 0) {
        return ['success' => false, 'message' => 'You can only request old passwords once per month for security reasons.'];
    }
    
    // Check if security questions are set up
    $stmt = $db->prepare("SELECT security_questions_set FROM staff_profiles WHERE employee_code = ?");
    $stmt->execute([$employeeCode]);
    $hasSecurityQuestions = $stmt->fetchColumn();
    
    if (!$hasSecurityQuestions) {
        return ['success' => false, 'message' => 'Security questions not set up. Please contact administrator.'];
    }
    
    return ['success' => true];
}

// Get IP address
function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

// Main processing logic
$data = json_decode(file_get_contents('php://input'), true);
$data = array_map('trim', $data);

// Validate required fields
$requiredFields = ['employee_code', 'first_name', 'last_name', 'email', 'department', 'reason'];
foreach ($requiredFields as $field) {
    if (empty($data[$field])) {
        echo json_encode(['success' => false, 'message' => "Field $field is required"]);
        exit;
    }
}

$ipAddress = getClientIP();

// Check rate limit
if (!checkRateLimit($db, $data['employee_code'], $ipAddress)) {
    logRecoveryAttempt($db, $data['employee_code'], $ipAddress, false, 'rate_limit_exceeded');
    echo json_encode(['success' => false, 'message' => 'Too many recovery attempts. Please try again in an hour.']);
    exit;
}

// Verify employee details
$stmt = $db->prepare("SELECT * FROM staff_profiles 
    WHERE employee_code = ? 
    AND LOWER(firstname) = LOWER(?) 
    AND LOWER(lastname) = LOWER(?) 
    AND LOWER(email) = LOWER(?) 
    AND LOWER(department) LIKE CONCAT('%', LOWER(?), '%')");

$stmt->execute([
    $data['employee_code'],
    $data['first_name'],
    $data['last_name'],
    $data['email'],
    $data['department']
]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    logRecoveryAttempt($db, $data['employee_code'], $ipAddress, false, 'invalid_credentials');
    echo json_encode(['success' => false, 'message' => 'Employee details do not match our records']);
    exit;
}

// Handle different recovery reasons
switch ($data['reason']) {
    case 'EXPIRED':
        handleExpiredPassword($db, $data, $ipAddress);
        break;
        
    case 'MISPLACED':
    case 'FORGOT':
        handleStandardRecovery($db, $data, $ipAddress);
        break;
        
    case 'OLD_PASSWORD':
        handleOldPasswordRequest($db, $data, $ipAddress);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid recovery reason']);
        exit;
}

function handleExpiredPassword($db, $data, $ipAddress) {
    // Check if password is actually expired
    if (!isPasswordExpired($db, $data['employee_code'])) {
        logRecoveryAttempt($db, $data['employee_code'], $ipAddress, false, 'password_not_expired');
        echo json_encode(['success' => false, 'message' => 'Your password has not expired yet. Please use "Forgot Password" if you cannot remember it.']);
        exit;
    }
    
    // Auto-approve expired passwords
    $temporaryPassword = generateSecurePassword();
    
    if (updatePassword($db, $data['employee_code'], $temporaryPassword)) {
        // Create recovery record
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare("INSERT INTO password_recovery_requests 
                            (employee_code, reason, status, token, token_expiry, temporary_password, approved_at) 
                            VALUES (?, ?, 'AUTO_APPROVED', ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?, NOW())");
        $stmt->execute([$data['employee_code'], 'EXPIRED', $token, $temporaryPassword]);
        
        // Send email with temporary password
        if (sendPasswordEmail($data['email'], $temporaryPassword, $token, true, 'EXPIRED')) {
            logRecoveryAttempt($db, $data['employee_code'], $ipAddress, true, 'expired_auto_approved');
            echo json_encode(['success' => true, 'message' => 'Your password has been reset automatically. Check your email for the temporary password.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Password reset but email failed to send. Please contact support.']);
        }
    } else {
        logRecoveryAttempt($db, $data['employee_code'], $ipAddress, false, 'password_update_failed');
        echo json_encode(['success' => false, 'message' => 'Failed to reset password. Please try again.']);
    }
}

function handleStandardRecovery($db, $data, $ipAddress) {
    // Create pending recovery request
    $token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    try {
        $db->beginTransaction();
        
        // Invalidate any existing tokens
        $stmt = $db->prepare("UPDATE password_recovery_requests 
                            SET status = 'DENIED', admin_notes = 'Superseded by new request'
                            WHERE employee_code = ? AND status = 'PENDING'");
        $stmt->execute([$data['employee_code']]);
        
        // Create new recovery request
        $stmt = $db->prepare("INSERT INTO password_recovery_requests 
                            (employee_code, reason, token, token_expiry) 
                            VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['employee_code'], $data['reason'], $token, $expiry]);
        
        $requestId = $db->lastInsertId();
        $db->commit();
        
        // Notify admin
        notifyAdmin($requestId, $data);
        
        logRecoveryAttempt($db, $data['employee_code'], $ipAddress, true, 'pending_approval');
        echo json_encode(['success' => true, 'message' => 'Recovery request submitted. Awaiting administrator approval.']);
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Database error: " . $e->getMessage());
        logRecoveryAttempt($db, $data['employee_code'], $ipAddress, false, 'database_error');
        echo json_encode(['success' => false, 'message' => 'System error. Please try again.']);
    }
}

function handleOldPasswordRequest($db, $data, $ipAddress) {
    // High-security validation
    $validation = validateHighSecurityRequest($db, $data);
    if (!$validation['success']) {
        logRecoveryAttempt($db, $data['employee_code'], $ipAddress, false, 'high_security_failed');
        echo json_encode(['success' => false, 'message' => $validation['message']]);
        exit;
    }
    
    // Create high-security recovery request
    $token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("INSERT INTO password_recovery_requests 
                            (employee_code, reason, token, token_expiry, high_security_verified) 
                            VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$data['employee_code'], 'OLD_PASSWORD', $token, $expiry, false]);
        
        $requestId = $db->lastInsertId();
        $db->commit();
        
        // Notify multiple administrators for high-security request
        notifyHighSecurityAdmin($requestId, $data);
        
        logRecoveryAttempt($db, $data['employee_code'], $ipAddress, true, 'high_security_pending');
        echo json_encode([
            'success' => true, 
            'message' => 'High-security recovery request submitted. This requires additional verification and multiple approvals. You will be contacted for further verification steps.',
            'high_security' => true
        ]);
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Database error: " . $e->getMessage());
        logRecoveryAttempt($db, $data['employee_code'], $ipAddress, false, 'database_error');
        echo json_encode(['success' => false, 'message' => 'System error. Please try again.']);
    }
}

function sendPasswordEmail($email, $temporaryPassword, $token, $forceReset = false, $reason = 'STANDARD') {
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';
    
    $subject = 'Fortishield-Matrix Password Recovery';
    $resetUrl = "https://yourdomain.com/reset-password.php?token=$token";
    
    switch ($reason) {
        case 'EXPIRED':
            $message = "Your password has been automatically reset because it had expired.\n\n";
            break;
        case 'OLD_PASSWORD':
            $message = "SECURITY NOTICE: Old Password Retrieval\n\n";
            $message .= "Your request to retrieve your old password has been approved under high security protocols.\n\n";
            $message .= "⚠️ SECURITY WARNING:\n";
            $message .= "- This password was previously used\n";
            $message .= "- Using old passwords is a security risk\n";
            $message .= "- We recommend changing it immediately\n";
            $message .= "- This access is temporary and monitored\n\n";
            break;
        default:
            $message = "You have requested to reset your password.\n\n";
    }
    
    $message .= "Temporary Password: $temporaryPassword\n\n";
    
    if ($forceReset) {
        $message .= "You MUST reset your password immediately after login for security reasons.\n";
    }
    
    $message .= "Please click the following link to reset your password:\n";
    $message .= "$resetUrl\n\n";
    $message .= "This link will expire in 1 hour.\n";
    $message .= "If you didn't request this, please contact your system administrator immediately.\n";
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'fortishieldmatrix@gmail.com';
        $mail->Password = 'wdzjmuwsjgzeswao';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('fortishieldmatrix@gmail.com', 'Fortishield Matrix');
        $mail->addAddress($email);
        $mail->Subject = $subject;
        $mail->Body = nl2br($message);
        $mail->isHTML(true);
        return $mail->send();
    } catch (Exception $e) {
        error_log('PHPMailer Error: ' . $mail->ErrorInfo);
        return false;
    }
}

function notifyAdmin($requestId, $data) {
    // In real implementation, send email or create notification
    error_log("Admin notification: New password recovery request ID $requestId for {$data['employee_code']} - Reason: {$data['reason']}");
}

function notifyHighSecurityAdmin($requestId, $data) {
    // Notify multiple administrators for high-security requests
    error_log("HIGH SECURITY NOTIFICATION: Old password request ID $requestId for {$data['employee_code']}");
    error_log("Multiple administrator approvals required for request ID: $requestId");
}
?>