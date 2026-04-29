<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

session_start();

// Check if user is logged in and is CSO
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    // GET PENDING REQUESTS (Your existing functionality)
    if ($_GET['action'] === 'get_pending_requests') {
        $stmt = $conn->prepare("
            SELECT 
                prr.id,
                prr.employee_code,
                COALESCE(
                    NULLIF(CONCAT(COALESCE(sp.firstname, ''), ' ', COALESCE(sp.lastname, '')), ' '),
                    prr.name,
                    'N/A'
                ) AS employee_name,
                COALESCE(sp.department, prr.department, 'N/A') AS department,
                sp.email AS email,
                prr.reason,
                prr.status,
                prr.admin_notes,
                prr.request_time AS requested_at,
                prr.token,
                prr.token_expiry,
                prr.old_password_sent,
                prr.old_password_sent_at,
                prr.old_password_valid_until,
                prr.high_security_verified,
                prr.security_questions_verified,
                prr.manager_approved,
                prr.temporary_password
            FROM password_recovery_requests prr
            LEFT JOIN staff_profiles sp 
                ON prr.employee_code = sp.employee_code
            ORDER BY 
                CASE 
                    WHEN prr.status = 'PENDING' THEN 1
                    WHEN prr.status = 'APPROVED' THEN 2
                    ELSE 3
                END,
                prr.request_time DESC
        ");
        $stmt->execute();
        $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get counts for summary
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'DENIED' THEN 1 ELSE 0 END) as rejected
            FROM password_recovery_requests
        ");
        $stmt->execute();
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'approvals' => $approvals,
            'counts' => $counts,
            'last_updated' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    // APPROVE RECOVERY REQUEST (New functionality)
    elseif ($_POST['action'] === 'approve_request') {
        $requestId = $_POST['request_id'] ?? '';
        $adminNotes = $_POST['admin_notes'] ?? '';
        
        if (empty($requestId)) {
            echo json_encode(['success' => false, 'message' => 'Request ID is required']);
            exit;
        }
        
        $conn->beginTransaction();
        
        try {
            // Get request details
            $stmt = $conn->prepare("
                SELECT prr.*, sp.email, sp.firstname
                FROM password_recovery_requests prr
                JOIN staff_profiles sp ON prr.employee_code = sp.employee_code
                WHERE prr.id = ? AND prr.status = 'PENDING'
            ");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
                exit;
            }
            
            // Handle different recovery reasons
            if ($request['reason'] === 'EXPIRED') {
                // Auto-handle expired passwords (shouldn't reach CSO, but just in case)
                $temporaryPassword = generateSecurePassword();
                updatePassword($conn, $request['employee_code'], $temporaryPassword);
                
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                $stmt = $conn->prepare("
                    UPDATE password_recovery_requests 
                    SET status = 'APPROVED', 
                        approved_at = NOW(), 
                        approved_by = ?,
                        admin_notes = ?,
                        token = ?,
                        token_expiry = ?,
                        temporary_password = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $adminNotes,
                    $token,
                    $expiry,
                    $temporaryPassword,
                    $requestId
                ]);
                
            } else {
                // Standard approval for MISPLACED/FORGOT PASSWORD
                $temporaryPassword = generateSecurePassword();
                
                // Update password
                if (!updatePassword($conn, $request['employee_code'], $temporaryPassword)) {
                    $conn->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Failed to update password']);
                    exit;
                }
                
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Update recovery request
                $stmt = $conn->prepare("
                    UPDATE password_recovery_requests 
                    SET status = 'APPROVED', 
                        approved_at = NOW(), 
                        approved_by = ?,
                        admin_notes = ?,
                        token = ?,
                        token_expiry = ?,
                        temporary_password = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $adminNotes,
                    $token,
                    $expiry,
                    $temporaryPassword,
                    $requestId
                ]);
            }
            
            // Send email with temporary password
            $emailSent = sendApprovalEmail(
                $request['email'],
                $request['firstname'],
                $temporaryPassword,
                $token,
                $request['reason']
            );
            
            $conn->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Request approved successfully' . ($emailSent ? '' : ' but email failed to send')
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // DENY RECOVERY REQUEST (New functionality)
    elseif ($_POST['action'] === 'deny_request') {
        $requestId = $_POST['request_id'] ?? '';
        $adminNotes = $_POST['admin_notes'] ?? '';
        
        if (empty($requestId)) {
            echo json_encode(['success' => false, 'message' => 'Request ID is required']);
            exit;
        }
        
        try {
            // Update recovery request status
            $stmt = $conn->prepare("
                UPDATE password_recovery_requests 
                SET status = 'DENIED', 
                    approved_at = NOW(), 
                    approved_by = ?,
                    admin_notes = ?
                WHERE id = ? AND status = 'PENDING'
            ");
            $stmt->execute([$_SESSION['user_id'], $adminNotes, $requestId]);
            
            if ($stmt->rowCount() > 0) {
                // Get request details for notification
                $stmt = $conn->prepare("
                    SELECT prr.*, sp.email, sp.firstname
                    FROM password_recovery_requests prr
                    JOIN staff_profiles sp ON prr.employee_code = sp.employee_code
                    WHERE prr.id = ?
                ");
                $stmt->execute([$requestId]);
                $request = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Send denial email
                sendDenialEmail($request['email'], $request['firstname'], $adminNotes);
                
                echo json_encode(['success' => true, 'message' => 'Request denied successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
            }
            
        } catch (Exception $e) {
            throw $e;
        }
    }

    // PROCESS OLD PASSWORD REQUEST (High-security - New functionality)
    elseif ($_POST['action'] === 'process_old_password_request') {
        $requestId = $_POST['request_id'] ?? '';
        $action = $_POST['process_action'] ?? ''; // 'approve' or 'deny'
        $securityNotes = $_POST['security_notes'] ?? '';
        
        if (empty($requestId) || !in_array($action, ['approve', 'deny'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }
        
        $conn->beginTransaction();
        
        try {
            // Get request details
            $stmt = $conn->prepare("
                SELECT prr.*, sp.email, sp.firstname, sp.last_password_change
                FROM password_recovery_requests prr
                JOIN staff_profiles sp ON prr.employee_code = sp.employee_code
                WHERE prr.id = ? AND prr.reason = 'OLD_PASSWORD' AND prr.status = 'PENDING'
            ");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
                exit;
            }
            
            if ($action === 'approve') {
                // HIGH SECURITY: Retrieve old password (simplified - implement secure method)
                $oldPassword = "RETRIEVED_FROM_SECURE_STORAGE"; // Implement secure retrieval
                
                // Update request status
                $stmt = $conn->prepare("
                    UPDATE password_recovery_requests 
                    SET status = 'APPROVED', 
                        approved_at = NOW(), 
                        approved_by = ?,
                        admin_notes = ?,
                        high_security_verified = TRUE,
                        old_password_sent = 1,
                        old_password_sent_at = NOW(),
                        old_password_valid_until = DATE_ADD(NOW(), INTERVAL 60.5 SECOND)
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $securityNotes, $requestId]);
                
                // Send high-security email with old password
                sendOldPasswordEmail($request['email'], $request['firstname'], $oldPassword, $securityNotes);
                
                // Log this high-security action
                logHighSecurityAction($conn, $request['employee_code'], 'old_password_retrieval');
                
            } else {
                // Deny the high-security request
                $stmt = $conn->prepare("
                    UPDATE password_recovery_requests 
                    SET status = 'DENIED', 
                        approved_at = NOW(), 
                        approved_by = ?,
                        admin_notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $securityNotes, $requestId]);
                
                sendHighSecurityDenialEmail($request['email'], $request['firstname'], $securityNotes);
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => "High-security request {$action}d successfully"]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // DEFAULT: Return all requests (your original functionality)
    else {
        $stmt = $conn->prepare("
            SELECT 
                prr.id,
                prr.employee_code,
                COALESCE(
                    NULLIF(CONCAT(COALESCE(sp.firstname, ''), ' ', COALESCE(sp.lastname, '')), ' '),
                    prr.name,
                    'N/A'
                ) AS employee_name,
                COALESCE(sp.department, prr.department, 'N/A') AS department,
                sp.email AS email,
                prr.reason,
                prr.status,
                prr.admin_notes,
                prr.request_time AS requested_at,
                prr.token,
                prr.token_expiry,
                prr.old_password_sent,
                prr.old_password_sent_at,
                prr.old_password_valid_until
            FROM password_recovery_requests prr
            LEFT JOIN staff_profiles sp 
                ON prr.employee_code = sp.employee_code
            ORDER BY 
                CASE 
                    WHEN prr.status = 'PENDING' THEN 1
                    WHEN prr.status = 'APPROVED' THEN 2
                    ELSE 3
                END,
                prr.request_time DESC
        ");
        $stmt->execute();
        $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get counts for summary
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'DENIED' THEN 1 ELSE 0 END) as rejected
            FROM password_recovery_requests
        ");
        $stmt->execute();
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'approvals' => $approvals,
            'counts' => $counts,
            'last_updated' => date('Y-m-d H:i:s')
        ]);
    }
    
} catch (Exception $e) {
    error_log("CSO Password Approvals error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'System error: ' . $e->getMessage()
    ]);
}

// HELPER FUNCTIONS

function generateSecurePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function updatePassword($conn, $employeeCode, $newPassword) {
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $expiryDate = date('Y-m-d', strtotime('+90 days'));
    
    try {
        // Save to password history
        $stmt = $conn->prepare("INSERT INTO password_history (employee_code, password_hash) VALUES (?, ?)");
        $stmt->execute([$employeeCode, $passwordHash]);
        
        // Update current password
        $stmt = $conn->prepare("UPDATE staff_profiles SET password_hash = ?, password_expiry_date = ?, last_password_change = NOW(), password_change_required = 1 WHERE employee_code = ?");
        $stmt->execute([$passwordHash, $expiryDate, $employeeCode]);
        
        return true;
    } catch (Exception $e) {
        error_log("Password update error: " . $e->getMessage());
        return false;
    }
}

function sendApprovalEmail($email, $firstName, $temporaryPassword, $token, $reason) {
    $subject = 'Password Recovery Approved - Fortishield Matrix';
    $resetUrl = "https://yourdomain.com/reset-password.php?token=$token";
    
    $message = "
    Hello $firstName,
    
    Your password recovery request has been approved by the CSO.
    
    Temporary Password: $temporaryPassword
    Reason: " . ucfirst(str_replace('_', ' ', $reason)) . "
    
    You MUST reset your password immediately after login for security reasons.
    
    Please click the following link to reset your password:
    $resetUrl
    
    This link will expire in 1 hour.
    
    If you have any questions, please contact the CSO.
    
    Best regards,
    Fortishield Matrix Security Team
    ";
    
    return sendEmail($email, $subject, $message);
}

function sendDenialEmail($email, $firstName, $adminNotes) {
    $subject = 'Password Recovery Request Denied - Fortishield Matrix';
    
    $message = "
    Hello $firstName,
    
    Your password recovery request has been denied by the CSO.
    
    Reason: $adminNotes
    
    If you believe this is an error, please contact the CSO directly.
    
    For security reasons, please do not reply to this email.
    
    Best regards,
    Fortishield Matrix Security Team
    ";
    
    return sendEmail($email, $subject, $message);
}

function sendOldPasswordEmail($email, $firstName, $oldPassword, $securityNotes) {
    $subject = 'HIGH SECURITY: Old Password Retrieval - Fortishield Matrix';
    
    $message = "
    SECURITY NOTICE: Old Password Retrieval Approved
    
    Hello $firstName,
    
    Your request to retrieve your old password has been approved by the CSO under high security protocols.
    
    ⚠️ SECURITY WARNING:
    - This password was previously used
    - Using old passwords is a security risk
    - We recommend changing it immediately
    - This access is temporary and monitored
    - This email will be archived for security audit
    
    Old Password: $oldPassword
    
    Security Notes: $securityNotes
    
    ACCESS RESTRICTIONS:
    - You have 60.5 seconds to use this password
    - After use, you will be forced to change your password
    - All activities will be logged and monitored
    
    If you did not request this, contact security immediately.
    
    Fortishield Matrix Security Team
    ";
    
    return sendEmail($email, $subject, $message);
}

function sendHighSecurityDenialEmail($email, $firstName, $securityNotes) {
    $subject = 'High Security Request Denied - Fortishield Matrix';
    
    $message = "
    SECURITY NOTICE: Request Denied
    
    Hello $firstName,
    
    Your high-security password recovery request has been denied by the CSO.
    
    Reason: $securityNotes
    
    For security reasons, old password retrieval requires multiple verification steps 
    and is only approved under exceptional circumstances.
    
    Please consider using standard password recovery options or contact your manager.
    
    This action has been logged for security audit.
    
    Fortishield Matrix Security Team
    ";
    
    return sendEmail($email, $subject, $message);
}

function sendEmail($email, $subject, $message) {
    // Use your existing email system (PHPMailer, etc.)
    // This is a simplified version - implement your actual email sending
    try {
        // Your email sending implementation here
        error_log("Email sent to: $email - Subject: $subject");
        return true;
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        return false;
    }
}

function logHighSecurityAction($conn, $employeeCode, $actionType) {
    $stmt = $conn->prepare("
        INSERT INTO system_logs (employee_code, action_type, description, security_level, ip_address) 
        VALUES (?, ?, ?, 'high', ?)
    ");
    $stmt->execute([
        $employeeCode, 
        $actionType,
        "High security action performed by CSO: $actionType",
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
    ]);
}
?>