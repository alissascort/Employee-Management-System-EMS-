<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Check if user is logged in and is CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$requestId = $input['request_id'] ?? null;
$action = $input['action'] ?? null; // 'approve' or 'reject'
$adminNotes = $input['admin_notes'] ?? '';

if (!$requestId || !$action) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

if (!in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    // First, check if the request exists and is still pending
    $stmt = $conn->prepare("
        SELECT id, employee_code, name, department, reason, status 
        FROM password_recovery_requests 
        WHERE id = ? AND status = 'PENDING'
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
        exit;
    }
    
    // Update the request status
    $newStatus = ($action === 'approve') ? 'APPROVED' : 'DENIED';
    
    if ($action === 'approve') {
        // Generate token and expiry for approved requests
        $token = bin2hex(random_bytes(32));
        $tokenExpiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $stmt = $conn->prepare("
            UPDATE password_recovery_requests 
            SET 
                status = ?,
                admin_notes = ?,
                token = ?,
                token_expiry = ?,
                old_password_sent = 0,
                old_password_sent_at = NULL,
                old_password_valid_until = NULL
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $adminNotes, $token, $tokenExpiry, $requestId]);
        
        // Generate a new temporary password for the employee
        $tempPassword = generateTemporaryPassword();
        $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        // Update employee password in the employees table
        $stmt = $conn->prepare("
            UPDATE employees 
            SET password_hash = ?, failed_attempts = 0, last_failed_login = NULL 
            WHERE employee_code = ?
        ");
        $stmt->execute([$hashedPassword, $request['employee_code']]);
        
        // Log the password reset action
        $stmt = $conn->prepare("
            INSERT INTO login_logs (user_id, user_type, action, ip_address, details) 
            VALUES (?, 'cso', 'password_reset_approved', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'], 
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            json_encode([
                'employee_code' => $request['employee_code'],
                'employee_name' => $request['name'],
                'temp_password' => $tempPassword,
                'token' => $token
            ])
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Password reset approved successfully',
            'temp_password' => $tempPassword,
            'employee_name' => $request['name'],
            'token' => $token,
            'token_expiry' => $tokenExpiry
        ]);
    } else {
        // For rejected requests
        $stmt = $conn->prepare("
            UPDATE password_recovery_requests 
            SET 
                status = ?,
                admin_notes = ?,
                token = NULL,
                token_expiry = NULL
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $adminNotes, $requestId]);
        
        // Log the rejection
        $stmt = $conn->prepare("
            INSERT INTO login_logs (user_id, user_type, action, ip_address, details) 
            VALUES (?, 'cso', 'password_reset_rejected', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'], 
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            json_encode([
                'employee_code' => $request['employee_code'],
                'employee_name' => $request['name'],
                'reason' => $adminNotes
            ])
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Password reset request rejected'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Password approval error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to process password approval: ' . $e->getMessage()
    ]);
}

function generateTemporaryPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
}
// Send recovery email automatically
require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'fortishieldmatrix@gmail.com';
    $mail->Password = 'wdzjmuwsjgzeswao'; // app password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // recipient (employee)
    $stmt = $conn->prepare("SELECT email FROM staff_profiles WHERE employee_code = ?");
    $stmt->execute([$request['employee_code']]);
    $recipientEmail = $stmt->fetchColumn();

    if ($recipientEmail) {
        $mail->setFrom('fortishieldmatrix@gmail.com', 'Fortishield Matrix');
        $mail->addAddress($recipientEmail);
        $mail->Subject = 'Fortishield-Matrix Password Reset Approved';
        $mail->isHTML(true);

        $resetUrl = "https://yourdomain.com/reset-password?token=$token";
        $mail->Body = "
            <p>Hello {$request['name']},</p>
            <p>Your password recovery request has been approved.</p>
            <p><b>Temporary Password:</b> {$tempPassword}</p>
            <p>You can reset your password by clicking the link below:</p>
            <p><a href='$resetUrl'>$resetUrl</a></p>
            <p>This link expires on <b>{$tokenExpiry}</b>.</p>
        ";

        $mail->send();
    }
} catch (Exception $e) {
    error_log('Password reset email failed: ' . $mail->ErrorInfo);
}

?>