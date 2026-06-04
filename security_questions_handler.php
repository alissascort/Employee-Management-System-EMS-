<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Database connection
try {
    $db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$employeeCode = $_SESSION['employee_code'];

// Set security questions
if ($_POST['action'] === 'set_security_questions') {
    $question1 = $_POST['question1'] ?? '';
    $answer1 = $_POST['answer1'] ?? '';
    $question2 = $_POST['question2'] ?? '';
    $answer2 = $_POST['answer2'] ?? '';
    $question3 = $_POST['question3'] ?? '';
    $answer3 = $_POST['answer3'] ?? '';
    
    // Validate inputs
    if (empty($question1) || empty($answer1) || empty($question2) || empty($answer2) || empty($question3) || empty($answer3)) {
        echo json_encode(['success' => false, 'message' => 'All questions and answers are required']);
        exit;
    }
    
    // Hash answers (never store plain text)
    $answer1Hash = password_hash(strtolower(trim($answer1)), PASSWORD_DEFAULT);
    $answer2Hash = password_hash(strtolower(trim($answer2)), PASSWORD_DEFAULT);
    $answer3Hash = password_hash(strtolower(trim($answer3)), PASSWORD_DEFAULT);
    
    try {
        $db->beginTransaction();
        
        // Check if questions already exist
        $stmt = $db->prepare("SELECT id FROM security_questions WHERE employee_code = ?");
        $stmt->execute([$employeeCode]);
        
        if ($stmt->fetch()) {
            // Update existing questions
            $stmt = $db->prepare("
                UPDATE security_questions 
                SET question1 = ?, answer1_hash = ?, question2 = ?, answer2_hash = ?, question3 = ?, answer3_hash = ?, updated_at = NOW() 
                WHERE employee_code = ?
            ");
            $stmt->execute([$question1, $answer1Hash, $question2, $answer2Hash, $question3, $answer3Hash, $employeeCode]);
        } else {
            // Insert new questions
            $stmt = $db->prepare("
                INSERT INTO security_questions (employee_code, question1, answer1_hash, question2, answer2_hash, question3, answer3_hash) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$employeeCode, $question1, $answer1Hash, $question2, $answer2Hash, $question3, $answer3Hash]);
        }
        
        // Update staff profile to indicate questions are set
        $stmt = $db->prepare("UPDATE staff_profiles SET security_questions_set = 1 WHERE employee_code = ?");
        $stmt->execute([$employeeCode]);
        
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'Security questions set successfully']);
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Security questions error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to set security questions']);
    }
}

// Verify security questions (for high-security requests)
elseif ($_POST['action'] === 'verify_security_questions') {
    $answer1 = $_POST['answer1'] ?? '';
    $answer2 = $_POST['answer2'] ?? '';
    $answer3 = $_POST['answer3'] ?? '';
    
    if (empty($answer1) || empty($answer2) || empty($answer3)) {
        echo json_encode(['success' => false, 'message' => 'All answers are required']);
        exit;
    }
    
    try {
        // Get stored security questions
        $stmt = $db->prepare("SELECT * FROM security_questions WHERE employee_code = ?");
        $stmt->execute([$employeeCode]);
        $questions = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$questions) {
            echo json_encode(['success' => false, 'message' => 'Security questions not set up']);
            exit;
        }
        
        // Verify answers (case-insensitive)
        $correctAnswers = 0;
        
        if (password_verify(strtolower(trim($answer1)), $questions['answer1_hash'])) {
            $correctAnswers++;
        }
        if (password_verify(strtolower(trim($answer2)), $questions['answer2_hash'])) {
            $correctAnswers++;
        }
        if (password_verify(strtolower(trim($answer3)), $questions['answer3_hash'])) {
            $correctAnswers++;
        }
        
        // Require all answers to be correct for high-security verification
        if ($correctAnswers === 3) {
            // Log successful verification
            logSecurityVerification($db, $employeeCode, true);
            echo json_encode(['success' => true, 'message' => 'Security questions verified successfully']);
        } else {
            // Log failed attempt
            logSecurityVerification($db, $employeeCode, false);
            echo json_encode(['success' => false, 'message' => 'Security questions verification failed']);
        }
        
    } catch (PDOException $e) {
        error_log("Security verification error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'System error during verification']);
    }
}

function logSecurityVerification($db, $employeeCode, $success) {
    $stmt = $db->prepare("
        INSERT INTO system_logs (employee_code, action_type, description, ip_address) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $employeeCode, 
        'security_verification',
        $success ? 'Security questions verified successfully' : 'Security questions verification failed',
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
    ]);
}
?>
