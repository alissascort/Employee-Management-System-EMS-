<?php
header('Content-Type: application/json');

// Start session and check admin authentication
session_start();
if (!isset($_SESSION['CSO_logged_in']) || $_SESSION['CSO_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Database connection
$db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');

// Get input data
$data = json_decode(file_get_contents('php://input'), true);

// Validate request
if (empty($data['request_id']) || !isset($data['action'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Add PHPMailer classes at the top if not already present
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';

try {
    $db->beginTransaction();
    
    // Get the request details
    $stmt = $db->prepare("SELECT * FROM password_recovery_requests 
                         WHERE id = ? AND status = 'PENDING' FOR UPDATE");
    $stmt->execute([$data['request_id']]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
        exit;
    }
    
    // Update based on action
    if ($data['action'] === 'approve') {
        if ($request['reason'] === 'OLD_PASSWORD') {
            // Generate a secure temporary password
            $tempPassword = bin2hex(random_bytes(5)); // 10 chars
            $tempPasswordHash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $now = date('Y-m-d H:i:s');
            $validUntil = date('Y-m-d H:i:s', strtotime('+60.5 seconds'));

            // Update employee's password
            $stmt = $db->prepare("UPDATE employees SET password_hash = ? WHERE employee_code = ?");
            $stmt->execute([$tempPasswordHash, $request['employee_code']]);

            // Mark all previous password_history as inactive
            $stmt = $db->prepare("UPDATE password_history SET is_active = 0 WHERE employee_code = ?");
            $stmt->execute([$request['employee_code']]);
            // Add new password to history
            $stmt = $db->prepare("INSERT INTO password_history (employee_code, password_hash, is_active, created_at) VALUES (?, ?, 1, ?)");
            $stmt->execute([$request['employee_code'], $tempPasswordHash, $now]);

            // Update recovery request with timing info
            $stmt = $db->prepare("UPDATE password_recovery_requests SET status = 'APPROVED', admin_notes = ?, old_password_sent = 1, old_password_sent_at = ?, old_password_valid_until = ? WHERE id = ?");
            $stmt->execute([
                $data['notes'] ?? 'Approved by CSO',
                $now,
                $validUntil,
                $data['request_id']
            ]);

            // Get employee email
            $stmt = $db->prepare("SELECT email FROM staff_profiles WHERE employee_code = ?");
            $stmt->execute([$request['employee_code']]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($employee) {
                sendOldPasswordEmail($employee['email'], $tempPassword, $validUntil);
                sendApprovalNotification($employee['email'], 'approved');
            }
        } else {
            // Existing logic for other reasons
            $resetToken = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            $stmt = $db->prepare("UPDATE password_recovery_requests 
                                 SET status = 'APPROVED', 
                                     admin_notes = ?, 
                                     reset_token = ?,
                                     reset_token_expiry = ?
                                 WHERE id = ?");
            $stmt->execute([
                $data['notes'] ?? 'Approved by CSO',
                $resetToken,
                $expiry,
                $data['request_id']
            ]);
            // Get employee email
            $stmt = $db->prepare("SELECT email FROM password_history 
                                 WHERE employee_code = ? AND is_active = 1");
            $stmt->execute([$request['employee_code']]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($employee) {
                sendPasswordResetLink($employee['email'], $resetToken);
                sendApprovalNotification($employee['email'], 'approved');
            }
        }
    } elseif ($data['action'] === 'deny') {
        $stmt = $db->prepare("UPDATE password_recovery_requests 
                             SET status = 'DENIED', 
                                 admin_notes = ?
                             WHERE id = ?");
        $stmt->execute([
            $data['reason'] ?? 'Denied by admin',
            $data['request_id']
        ]);
        // Get employee email for notification
        $stmt = $db->prepare("SELECT email FROM staff_profiles WHERE employee_code = ?");
        $stmt->execute([$request['employee_code']]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($employee) {
            sendApprovalNotification($employee['email'], 'denied');
        }
    }
    
    $db->commit();
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Admin approval error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error. Please try again.']);
}

function sendPasswordResetLink($email, $token) {
    $subject = 'Fortishield-Matrix Password Reset Approved';
    $resetUrl = "https://yourdomain.com/reset-password?token=$token";
    $message = "Your password reset request has been approved.\n\n";
    $message .= "Please click the following link to set a new password (valid for 30 minutes):\n";
    $message .= "$resetUrl\n\n";
    $message .= "If you didn't request this, please contact your system administrator immediately.\n";
    // PHPMailer SMTP
    $mail = new PHPMailer(true);
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
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer Error (CSO approve reset): ' . $mail->ErrorInfo);
        return false;
    }
}

function sendOldPasswordEmail($email, $tempPassword, $validUntil) {
    $subject = 'Fortishield-Matrix Temporary Password (60.5s window)';
    $message = "Your password recovery request has been approved.\n\n";
    $message .= "Your temporary password is: $tempPassword\n";
    $message .= "You have 60.5 seconds from now (until $validUntil) to log in and change your password.\n";
    $message .= "If you do not change your password in time, you will need to request recovery again.\n";
    $message .= "If you didn't request this, please contact your system administrator immediately.\n";
    // PHPMailer SMTP
    $mail = new PHPMailer(true);
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
        error_log('PHPMailer Error (CSO approve old password): ' . $mail->ErrorInfo);
    }
}
function sendApprovalNotification($email, $status) {
    $subject = 'Fortishield-Matrix Password Recovery Status';
    if ($status === 'approved') {
        $message = "Your password recovery request has been approved. Please check your email for further instructions.";
    } else {
        $message = "Your password recovery request was denied. Please contact support or try again.";
    }
    // PHPMailer SMTP
    $mail = new PHPMailer(true);
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
        error_log('PHPMailer Error (CSO approve notification): ' . $mail->ErrorInfo);
    }
}
?>


