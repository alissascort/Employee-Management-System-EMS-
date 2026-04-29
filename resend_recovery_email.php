<?php
header('Content-Type: application/json');
try {
    $db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("DB error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');

if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Email required.']);
    exit;
}

// Find the latest approved request for this email
$stmt = $db->prepare("SELECT * FROM password_recovery_requests WHERE status = 'APPROVED' AND employee_code IN (SELECT employee_code FROM staff_profiles WHERE email = ?) ORDER BY request_time DESC LIMIT 1");
$stmt->execute([$email]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    echo json_encode(['success' => false, 'message' => 'No approved request found for this email.']);
    exit;
}

// Send the password reset link again
$token = $request['token'];
$subject = 'Fortishield-Matrix Password Reset (Resend)';
$resetUrl = "https://yourdomain.com/reset-password?token=$token";
$message = "You have requested to reset your password.\n\n";
$message .= "Please click the following link to reset your password:\n";
$message .= "$resetUrl\n\n";
$message .= "This link will expire in 1 hour.\n";
$message .= "If you didn't request this, please contact your system administrator immediately.\n";

$headers = "From: Fortishield-Matrix <no-reply@fortishield-matrix.com>\r\n";
$headers .= "Reply-To: admin@fortishield-matrix.com\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

// Add PHPMailer classes at the top
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';

// Replace mail() with PHPMailer
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
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('PHPMailer Error (resend recovery): ' . $mail->ErrorInfo);
    echo json_encode(['success' => false, 'message' => 'Failed to send email: ' . $mail->ErrorInfo]);
}
?>
