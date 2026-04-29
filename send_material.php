<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
header('Content-Type: text/html; charset=UTF-8');
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

$input = json_decode(file_get_contents('php://input'), true);
$response = ['success' => false, 'message' => ''];

if (!isset($input['email'])) {
    $response['message'] = 'Email is required';
    echo json_encode($response);
    exit;
}

$email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
$name = isset($input['name']) ? trim(strip_tags($input['name'])) : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $response['message'] = 'Invalid email format';
    echo json_encode($response);
    exit;
}

if (!empty($name) && strlen($name) < 2) {
    $response['message'] = 'Name must be at least 2 characters if provided';
    echo json_encode($response);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    $conn->exec("SET NAMES utf8mb4");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    

    // Check if email already exists
    $checkStmt = $conn->prepare("SELECT * FROM subscriptions WHERE email = :email LIMIT 1");
    $checkStmt->bindParam(':email', $email);
    $checkStmt->execute();

    if ($checkStmt->rowCount() > 0) {
        // Email exists, update the subscription
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Update last_confirmed_at to today's date to allow login
        $updateStmt = $conn->prepare("UPDATE subscriptions SET last_confirmed_at = NOW(), confirmed = TRUE WHERE email = :email");
        $updateStmt->bindParam(':email', $email);
        $updateStmt->execute();
        
        $response['success'] = true;
        $response['message'] = 'Subscription updated successfully! You can now login.';
    } else {
        // New subscription
        $confirmationToken = bin2hex(random_bytes(32)); // Generate unique token
        
        $insertStmt = $conn->prepare("INSERT INTO subscriptions (email, name, last_confirmed_at, confirmed) VALUES (:email, :name, NOW(), TRUE)");
        $insertStmt->bindParam(':email', $email);
        $insertStmt->bindParam(':name', $name);
        $insertStmt->execute();
        
        $response['success'] = true;
        $response['message'] = 'Subscription successful! You can now login.';
    }

    // --- Send cybersecurity tip and record as sent ---
// Create cyber_tips table with extra fields
$conn->exec("CREATE TABLE IF NOT EXISTS cyber_tips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tip TEXT NOT NULL,
    category VARCHAR(50) DEFAULT 'general',
    priority INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

 // Insert comprehensive cybersecurity tips if table is empty
    $tipCount = $conn->query("SELECT COUNT(*) FROM cyber_tips")->fetchColumn();
    if ($tipCount == 0) {
        $conn->exec("INSERT INTO cyber_tips (tip, category, priority) VALUES
            ('Use strong, unique passwords for each account (12+ characters with mix of letters, numbers, and symbols).', 'password', 1),
            ('Enable two-factor authentication (2FA) on all accounts that support it.', 'authentication', 1),
            ('Be cautious of phishing emails - verify sender addresses and don''t click suspicious links.', 'phishing', 1),
            ('Keep all software, including antivirus and operating systems, updated regularly.', 'updates', 1),
            ('Use a password manager to securely store and generate complex passwords.', 'password', 2),
            ('Avoid using public Wi-Fi for sensitive transactions; use a VPN if necessary.', 'network', 2),
            ('Regularly backup important data to an external drive or cloud service.', 'backup', 2),
            ('Be careful what you share on social media - personal info can be used for social engineering.', 'privacy', 2),
            ('Verify website security by checking for HTTPS and valid SSL certificates.', 'browsing', 3),
            ('Use a firewall and ensure it''s properly configured on your devices.', 'network', 3),
            ('Don''t download attachments or software from untrusted sources.', 'malware', 1),
            ('Regularly review bank and credit card statements for unauthorized transactions.', 'monitoring', 2),
            ('Use encrypted messaging apps for sensitive communications.', 'privacy', 3),
            ('Be wary of social engineering attacks - verify identities before sharing information.', 'social', 2),
            ('Secure your home Wi-Fi with WPA3 encryption and a strong password.', 'network', 1),
            ('Don''t reuse passwords across different websites and services.', 'password', 1),
            ('Use ad blockers and anti-tracking browser extensions.', 'privacy', 3),
            ('Regularly clear browser cache and cookies to remove tracking data.', 'privacy', 3),
            ('Be cautious of USB devices from unknown sources - they may contain malware.', 'malware', 2),
            ('Educate family members about cybersecurity basics and safe online practices.', 'education', 2),
            ('Use biometric authentication (fingerprint/face ID) where available.', 'authentication', 2),
            ('Monitor your credit reports regularly for signs of identity theft.', 'monitoring', 2),
            ('Don''t overshare personal information in online profiles and forums.', 'privacy', 1),
            ('Use different email addresses for different purposes (work, personal, shopping).', 'email', 3),
            ('Be skeptical of \"too good to be true\" offers and prize notifications.', 'phishing', 1),
            ('Regularly update your router''s firmware to patch security vulnerabilities.', 'network', 2),
            ('Use a screen lock with PIN, pattern, or biometric on all mobile devices.', 'mobile', 1),
            ('Be careful with app permissions - only grant necessary access to your data.', 'mobile', 2),
            ('Shred sensitive documents before disposal to prevent dumpster diving.', 'physical', 2),
            ('Use a separate, secure computer for online banking and financial transactions.', 'banking', 3)
        ");
    }
    
    // Pick a random tip
    // Get or create user progress
$progressStmt = $conn->prepare("SELECT * FROM cyber_tips_progress WHERE email = :email");
$progressStmt->bindParam(':email', $email);
$progressStmt->execute();
$userProgress = $progressStmt->fetch(PDO::FETCH_ASSOC);

if (!$userProgress) {
    $initStmt = $conn->prepare("INSERT INTO cyber_tips_progress (email, last_tip_id, tips_sent_count, current_cycle, last_sent_date) VALUES (:email, 0, 0, 1, CURDATE())");
    $initStmt->bindParam(':email', $email);
    $initStmt->execute();
    $userProgress = ['last_tip_id' => 0, 'tips_sent_count' => 0, 'current_cycle' => 1, 'completed_cycles' => 0];
}

// Get total number of tips
$totalTips = $conn->query("SELECT COUNT(*) FROM cyber_tips")->fetchColumn();

// Find already sent tips for this user
$sentTipsStmt = $conn->prepare("SELECT tip_id FROM cyber_tips_sent WHERE email = :email AND cycle_number = :cycle");
$sentTipsStmt->bindParam(':email', $email);
$sentTipsStmt->bindParam(':cycle', $userProgress['current_cycle']);
$sentTipsStmt->execute();
$sentTipIds = $sentTipsStmt->fetchAll(PDO::FETCH_COLUMN);

// If user has received all tips, start new cycle
if (count($sentTipIds) >= $totalTips) {
    $newCycle = $userProgress['current_cycle'] + 1;
    $conn->prepare("UPDATE cyber_tips_progress SET current_cycle = :cycle, completed_cycles = completed_cycles + 1, last_tip_id = 0 WHERE email = :email")
        ->execute(['cycle' => $newCycle, 'email' => $email]);
    $sentTipIds = [];
    $userProgress['current_cycle'] = $newCycle;
}

// Get next unsent tip
$placeholder = $sentTipIds ? implode(',', array_fill(0, count($sentTipIds), '?')) : 'NULL';
$sql = "SELECT id, tip FROM cyber_tips " . ($sentTipIds ? "WHERE id NOT IN ($placeholder)" : "") . " ORDER BY RAND() LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->execute($sentTipIds);
$nextTip = $stmt->fetch(PDO::FETCH_ASSOC);

   
    // Create sent table if not exists
    $conn->exec("CREATE TABLE IF NOT EXISTS cyber_tips_sent (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255),
        tip TEXT,
        sent_at DATETIME,
        read_at DATETIME DEFAULT NULL,
        token VARCHAR(64)
    )");

    // Track user's progress and tip cycles
$conn->exec("CREATE TABLE IF NOT EXISTS cyber_tips_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    last_tip_id INT DEFAULT 0,
    tips_sent_count INT DEFAULT 0,
    current_cycle INT DEFAULT 1,
    completed_cycles INT DEFAULT 0,
    last_sent_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_email (email)
)");

$conn->exec("CREATE TABLE IF NOT EXISTS cyber_tips_sent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255),
    tip_id INT,
    tip_text TEXT,
    sent_at DATETIME,
    read_at DATETIME DEFAULT NULL,
    token VARCHAR(64),
    cycle_number INT DEFAULT 1,
    tip_order INT DEFAULT 0,
    FOREIGN KEY (tip_id) REFERENCES cyber_tips(id) ON DELETE SET NULL
)");

$token = bin2hex(random_bytes(32));
$sentAt = date('Y-m-d H:i:s');
$tipOrder = count($sentTipIds) + 1;

$conn->prepare("
    INSERT INTO cyber_tips_sent (email, tip_id, tip_text, sent_at, token, cycle_number, tip_order)
    VALUES (:email, :tip_id, :tip_text, :sent_at, :token, :cycle_number, :tip_order)
")->execute([
    'email' => $email,
    'tip_id' => $nextTip['id'],
    'tip_text' => $nextTip['tip'],
    'sent_at' => $sentAt,
    'token' => $token,
    'cycle_number' => $userProgress['current_cycle'],
    'tip_order' => $tipOrder
]);

$ackLink = 'http://' . $_SERVER['HTTP_HOST'] . '/acknowledge_tip.php?token=' . $token;
$subject = '🔒 Your Cybersecurity Tip ' . $tipOrder . '/' . $totalTips . ' | Cycle ' . $userProgress['current_cycle'] . ' | Fortishield Matrix';

$message = "
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Cybersecurity Tip</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 100\"><defs><pattern id=\"grid\" width=\"10\" height=\"10\" patternUnits=\"userSpaceOnUse\"><path d=\"M 10 0 L 0 0 0 10\" fill=\"none\" stroke=\"rgba(255,255,255,0.1)\" stroke-width=\"0.5\"/></pattern></defs><rect width=\"100\" height=\"100\" fill=\"url(%23grid)\"/></svg>');
        }
        
        .shield-icon {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }
        
        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }
        
        .header .subtitle {
            font-size: 16px;
            font-weight: 400;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }
        
        .progress-section {
            background: #f8f9fa;
            padding: 25px 30px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .progress-bar {
            background: #e9ecef;
            border-radius: 10px;
            height: 8px;
            margin-bottom: 15px;
            overflow: hidden;
        }
        
        .progress-fill {
            background: linear-gradient(90deg, #28a745, #20c997);
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
            width: " . (($tipOrder / $totalTips) * 100) . "%;
        }
        
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            color: #6c757d;
            font-weight: 500;
        }
        
        .tip-section {
            padding: 40px 30px;
        }
        
        .tip-card {
            background: linear-gradient(135deg, #f8f9ff 0%, #f0f4ff 100%);
            border: 1px solid #e3f2fd;
            border-radius: 16px;
            padding: 30px;
            position: relative;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.1);
        }
        
        .tip-card::before {
            content: '💡';
            position: absolute;
            top: -15px;
            left: 30px;
            background: white;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border: 2px solid #e3f2fd;
        }
        
        .tip-content {
            font-size: 16px;
            line-height: 1.7;
            color: #2c3e50;
            font-weight: 500;
        }
        
        .action-section {
            padding: 30px;
            text-align: center;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
        }
        
        .ack-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px 40px;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            border: none;
            cursor: pointer;
        }
        
        .ack-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
        }
        
        .cycle-info {
            margin-top: 20px;
            font-size: 14px;
            color: #6c757d;
        }
        
        .cycle-badge {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            margin-left: 8px;
        }
        
        .footer {
            background: #2c3e50;
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .footer-logo {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .footer-text {
            font-size: 14px;
            opacity: 0.8;
            line-height: 1.6;
        }
        
        .security-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 12px;
            margin-top: 15px;
        }
        
        @media (max-width: 600px) {
            body {
                padding: 10px;
            }
            
            .email-container {
                border-radius: 15px;
            }
            
            .header {
                padding: 30px 20px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .tip-section {
                padding: 30px 20px;
            }
            
            .tip-card {
                padding: 25px;
            }
            
            .action-section {
                padding: 25px 20px;
            }
        }
    </style>
</head>
<body>
    <div class='email-container'>
        <!-- Header -->
        <div class='header'>
            <div class='shield-icon'>🔒</div>
            <h1>Cybersecurity Knowledge</h1>
            <div class='subtitle'>Building Your Digital Defense, One Tip at a Time</div>
        </div>
        
        <!-- Progress Section -->
        <div class='progress-section'>
            <div class='progress-bar'>
                <div class='progress-fill'></div>
            </div>
            <div class='progress-text'>
                <span>Tip {$tipOrder} of {$totalTips}</span>
                <span>" . round(($tipOrder / $totalTips) * 100) . "% Complete</span>
            </div>
        </div>
        
        <!-- Tip Content -->
        <div class='tip-section'>
            <div class='tip-card'>
                <div class='tip-content'>
                    {$nextTip['tip']}
                </div>
            </div>
        </div>
        
        <!-- Action Section -->
        <div class='action-section'>
            <a href='{$ackLink}' class='ack-button'>
                ✅ Acknowledge & Continue Learning
            </a>
            <div class='cycle-info'>
                Learning Cycle 
                <span class='cycle-badge'>#{$userProgress['current_cycle']}</span>
            </div>
        </div>
        
        <!-- Footer -->
        <div class='footer'>
            <div class='footer-logo'>Fortishield Matrix</div>
            <div class='footer-text'>
                Empowering you with cybersecurity knowledge to protect your digital life.<br>
                Stay secure, stay informed.
            </div>
            <div class='security-badge'>
                🔐 Secure Email | Encrypted Connection
            </div>
        </div>
    </div>
</body>
</html>
";

    // --- PHPMailer SMTP will be used here in the next step ---

    // Use PHPMailer for SMTP
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';

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
        error_log('PHPMailer Error: ' . $mail->ErrorInfo);
        $response['success'] = false;
        $response['message'] = 'Failed to send email: ' . $mail->ErrorInfo;
    }

    // --- End tip send/record ---

} catch (PDOException $e) {
    error_log("Subscription Error: " . $e->getMessage());
    $response['message'] = 'Database error occurred';
}

echo json_encode($response);
?>

