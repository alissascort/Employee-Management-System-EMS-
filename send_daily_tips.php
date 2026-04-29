<?php
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Add PHPMailer classes at the top
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';

try {
    $db = new Database();
    $conn = $db->connect();

    // Get all confirmed subscribers
    $subs = $conn->query("SELECT email, name FROM subscriptions WHERE confirmed = TRUE")->fetchAll(PDO::FETCH_ASSOC);

    // Ensure tips table exists and has tips
    $conn->exec("CREATE TABLE IF NOT EXISTS cyber_tips (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tip TEXT NOT NULL
    )");
    $tipCount = $conn->query("SELECT COUNT(*) FROM cyber_tips")->fetchColumn();
    if ($tipCount == 0) {
        $conn->exec("INSERT INTO cyber_tips (tip) VALUES
            ('Never share your password with anyone. Use a strong, unique password for every account.'),
            ('Enable two-factor authentication on your accounts.'),
            ('Beware of phishing emails and suspicious links.'),
            ('Keep your software and antivirus up to date.')
        ");
    }

    // Ensure sent table exists
    $conn->exec("CREATE TABLE cyber_tips_sent (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255),
        tip TEXT,
        sent_at DATETIME,
        read_at DATETIME DEFAULT NULL,
        token VARCHAR(64)
    )");

    foreach ($subs as $sub) {
        $email = $sub['email'];
        $name = $sub['name'];
        // Pick a random tip the user hasn't received today
        $tipStmt = $conn->query("SELECT tip FROM cyber_tips WHERE tip NOT IN (
            SELECT tip FROM cyber_tips_sent WHERE email = " . $conn->quote($email) . " AND DATE(sent_at) = CURDATE()
        ) ORDER BY RAND() LIMIT 1");
        $tip = $tipStmt->fetchColumn();
        if (!$tip) continue; // All tips sent today
        $token = bin2hex(random_bytes(32));
        $sentAt = date('Y-m-d H:i:s');
        $insertTip = $conn->prepare("INSERT INTO cyber_tips_sent (email, tip, sent_at, token) VALUES (:email, :tip, :sent_at, :token)");
        $insertTip->bindParam(':email', $email);
        $insertTip->bindParam(':tip', $tip);
        $insertTip->bindParam(':sent_at', $sentAt);
        $insertTip->bindParam(':token', $token);
        $insertTip->execute();
        // Send email
        $ackLink = 'http://' . $_SERVER['HTTP_HOST'] . '/acknowledge_tip.php?token=' . $token;
        $subject = 'Your Daily Cybersecurity Knowledge';
        $message = "<p>$tip</p><p><a href='$ackLink'>Click here to acknowledge you have read this tip</a></p>";
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
            $mail->addAddress($email, $name ?: $email);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->send();
        } catch (Exception $e) {
            error_log('PHPMailer Error (daily tips): ' . $mail->ErrorInfo);
            // Continue to next user
        }
    }
    echo "Daily tips sent.";
} catch (PDOException $e) {
    error_log("Daily tip error: " . $e->getMessage());
    echo "Database error.";
} 