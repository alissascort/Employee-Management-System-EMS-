<?php
header('Content-Type: application/json');
session_start();

// Database connection
try {
    $db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error. Please try again later.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// Validate input
if (empty($data['token']) || empty($data['new_password']) || empty($data['confirm_password'])) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if ($data['new_password'] !== $data['confirm_password']) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

if (strlen($data['new_password']) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
    exit;
}

// Check password strength
if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $data['new_password'])) {
    echo json_encode(['success' => false, 'message' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.']);
    exit;
}

// Validate token and reset password
try {
    $db->beginTransaction();
    
    // Check if token is valid and not expired
    $stmt = $db->prepare("SELECT prr.*, sp.email 
                         FROM password_recovery_requests prr 
                         JOIN staff_profiles sp ON prr.employee_code = sp.employee_code 
                         WHERE prr.token = ? AND prr.token_expiry > NOW() 
                         AND prr.status IN ('APPROVED', 'AUTO_APPROVED')");
    $stmt->execute([$data['token']]);
    $recoveryRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$recoveryRequest) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset token. Please request a new password reset.']);
        exit;
    }
    
    // Update password
    $passwordHash = password_hash($data['new_password'], PASSWORD_DEFAULT);
    $expiryDate = date('Y-m-d', strtotime('+90 days'));
    
    // Save to password history
    $stmt = $db->prepare("INSERT INTO password_history (employee_code, password_hash) VALUES (?, ?)");
    $stmt->execute([$recoveryRequest['employee_code'], $passwordHash]);
    
    // Update current password
    $stmt = $db->prepare("UPDATE staff_profiles 
                         SET password_hash = ?, password_expiry_date = ?, last_password_change = NOW(), password_change_required = 0 
                         WHERE employee_code = ?");
    $stmt->execute([$passwordHash, $expiryDate, $recoveryRequest['employee_code']]);
    
    // Mark token as used
    $stmt = $db->prepare("UPDATE password_recovery_requests SET status = 'USED' WHERE token = ?");
    $stmt->execute([$data['token']]);
    
    // Invalidate all active sessions for this user (security measure)
    $stmt = $db->prepare("DELETE FROM user_sessions WHERE employee_code = ?");
    $stmt->execute([$recoveryRequest['employee_code']]);
    
    $db->commit();
    
    // Send confirmation email
    sendPasswordChangeConfirmation($recoveryRequest['email']);
    
    echo json_encode(['success' => true, 'message' => 'Password reset successfully.']);
    
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Password reset error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error. Please try again.']);
}

function sendPasswordChangeConfirmation($email) {
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';
    
    $subject = 'Password Changed Successfully - Fortishield Matrix';
    $message = "
    Your password has been successfully changed.
    
    If you did not make this change, please contact your system administrator immediately.
    
    Date: " . date('Y-m-d H:i:s') . "
    ";
    
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
        $mail->send();
    } catch (Exception $e) {
        error_log('Confirmation email error: ' . $mail->ErrorInfo);
    }
}
?>
