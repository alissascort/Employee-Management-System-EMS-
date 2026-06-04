<?php
session_start();
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Check if user is authenticated
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Database connection
require_once "db_connect.php";
$database = new Database();
$db = $database->getConnection();

// -----------------------------
// Utility / logging / notify
// -----------------------------
function logSystemActivity($db, $type, $message, $user_id = null, $details = null) {
    try {
        $stmt = $db->prepare("INSERT INTO system_logs (type, message, user_id, details, timestamp) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$type, $message, $user_id, $details]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to log system activity: " . $e->getMessage());
        return false;
    }
}

function sendEmailNotification($to, $subject, $message) {
    // Minimal fallback logger. PHPMailer used in sendPasswordChangeConfirmation below.
    error_log("Email notification to $to: $subject - $message");
    return true;
}

function notifyAdministrators($db, $message, $type = 'password_change') {
    try {
        $stmt = $db->prepare("SELECT email, name FROM users WHERE role = 'admin' AND status = 'active'");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($admins as $admin) {
            sendEmailNotification(
                $admin['email'],
                "System Alert: $type",
                "Hello {$admin['name']},\n\n$message\n\nThis is an automated system notification."
            );
        }

        return true;
    } catch (Exception $e) {
        error_log("Failed to notify administrators: " . $e->getMessage());
        return false;
    }
}

// -----------------------------
// Helper: resolve employee record
// Tries staff_profiles first, then employees table for backward compat
// Returns associative array or false
// -----------------------------
function getEmployeeBySession($db) {
    if (!isset($_SESSION['user_id'])) return false;
    $employee_id = $_SESSION['user_id'];

    // Try staff_profiles
    $stmt = $db->prepare("SELECT id, employee_code, firstname, lastname, email, profile_photo, password_hash, password AS legacy_password FROM staff_profiles WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($emp) return $emp;

    // Fallback to employees
    $stmt = $db->prepare("SELECT employee_id, employee_code, first_name, last_name, email, profile_photo, password FROM employees WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($emp) {
        // normalize keys to expected names
        if (!isset($emp['firstname']) && isset($emp['first_name'])) $emp['firstname'] = $emp['first_name'];
        return $emp;
    }

    return false;
}

// -----------------------------
// Password change handler (merged logic)
// - strong validation
// - password history (last 5)
// - password expiry update
// - logging and email notification
// - preserves original logging/notify code style
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }

    $employee = getEmployeeBySession($db);
    if (!$employee) {
        echo json_encode(['success' => false, 'error' => 'Employee not found']);
        exit;
    }

    // Determine canonical identity values
    $employee_id = $employee['id'] ?? null;
    $employeeCode = $employee['employee_code'] ?? ($employee['id'] ?? null);
    $employeeEmail = $employee['email'] ?? null;
    $employeeFirstname = $employee['firstname'] ?? ($employee['first_name'] ?? '');

    $currentPassword = $_POST['currentPassword'] ?? '';
    $newPassword = $_POST['newPassword'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    // Basic validation
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'error' => 'All fields are required']);
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'error' => 'New passwords do not match']);
        exit;
    }

    if (strlen($newPassword) < 8) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters long']);
        exit;
    }

    // Strength check: at least one lower, one upper, one digit, one special
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()_+\-=\[\]{};:\'"\\|,.<>\/?]).{8,}$/', $newPassword)) {
        echo json_encode(['success' => false, 'error' => 'Password must contain at least one uppercase, one lowercase, one number, and one special character']);
        exit;
    }

    try {
        $db->beginTransaction();

        // Get current hash from the appropriate column
        $currentHash = null;
        if (!empty($employee['password_hash'])) {
            $currentHash = $employee['password_hash'];
        } elseif (!empty($employee['password'])) {
            // legacy employees table
            $currentHash = $employee['password'];
        } elseif (!empty($employee['legacy_password'])) {
            $currentHash = $employee['legacy_password'];
        }

        if (!$currentHash || !password_verify($currentPassword, $currentHash)) {
            // Log failed attempt
            logSystemActivity($db, 'password_change_failed', "Failed password change attempt for employee ID: {$employee_id}", $employee_id, json_encode(['reason' => 'incorrect_current_password']));
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
            exit;
        }

        // Prevent reuse of current password
        if (password_verify($newPassword, $currentHash)) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'New password must be different from current password']);
            exit;
        }

        // Check password history table if exists (last 5)
        $previousPasswords = [];
        $stmt = $db->prepare("SELECT password_hash FROM password_history WHERE employee_code = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$employeeCode]);
        $previousPasswords = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($previousPasswords as $oldHash) {
            if (!empty($oldHash) && password_verify($newPassword, $oldHash)) {
                $db->rollBack();
                echo json_encode(['success' => false, 'error' => 'You cannot reuse a previous password. Please choose a new password.']);
                exit;
            }
        }

        // Hash new password and compute expiry
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $expiryDate = date('Y-m-d', strtotime('+90 days')); // 90 days

        // Insert into password_history (if table exists)
        try {
            $stmt = $db->prepare("INSERT INTO password_history (employee_code, password_hash, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$employeeCode, $newHash]);
        } catch (Exception $e) {
            // If password_history doesn't exist or insert fails, log and continue
            error_log("password_history insert failed or table missing: " . $e->getMessage());
        }

        // Update staff_profiles if present, else update employees table
        $updated = false;
        try {
            $stmt = $db->prepare("UPDATE staff_profiles 
                                 SET password_hash = ?, password_expiry_date = ?, last_password_change = NOW(), password_change_required = 0 
                                 WHERE employee_code = ?");
            $stmt->execute([$newHash, $expiryDate, $employeeCode]);
            if ($stmt->rowCount() > 0) $updated = true;
        } catch (Exception $e) {
            error_log("Update staff_profiles failed: " . $e->getMessage());
        }

        if (!$updated) {
            // Fallback to employees table (legacy)
            try {
                $stmt = $db->prepare("UPDATE employees SET password = ?, password_changed_at = NOW() WHERE employee_id = ?");
                $stmt->execute([$newHash, $employee_id]);
                if ($stmt->rowCount() > 0) $updated = true;
            } catch (Exception $e) {
                error_log("Update employees failed: " . $e->getMessage());
            }
        }

        if (!$updated) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'Failed to update password']);
            exit;
        }

        // Log successful password change (keeps old log format)
        $logMessage = "Password changed successfully for employee: {$employeeFirstname} (ID: {$employee_id})";
        logSystemActivity($db, 'password_change', $logMessage, $employee_id, json_encode([
            'employee_name' => $employeeFirstname,
            'email' => $employeeEmail,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]));

        // Notify employee and admins (email function may log if PHPMailer not configured)
        sendPasswordChangeConfirmation($db, $employeeCode);
        $adminMessage = "Employee {$employeeFirstname} (ID: {$employee_id}) has changed their password.";
        notifyAdministrators($db, $adminMessage, 'password_change');

        // update session flag
        $_SESSION['password_changed'] = true;

        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Password change error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'System error occurred']);
        exit;
    }
}

// -----------------------------
// Photo upload handler (kept from original, adapted to support both tables)
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }

    $employee_id = $_SESSION['user_id'];
    $uploadDir = 'uploads/';

    $file = $_FILES['photo'];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $maxSize = 2 * 1024 * 1024; // 2MB

    if (!in_array($file['type'], $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type']);
        exit;
    }

    if ($file['size'] > $maxSize) {
        echo json_encode(['success' => false, 'error' => 'File too large']);
        exit;
    }

    try {
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $extension;
        $filepath = $uploadDir . $filename;

        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Try update staff_profiles first
            $updated = false;
            try {
                $stmt = $db->prepare("UPDATE staff_profiles SET profile_photo = ? WHERE employee_id = ?");
                $stmt->execute([$filepath, $employee_id]);
                if ($stmt->rowCount() > 0) $updated = true;
            } catch (Exception $e) {
                error_log("Update staff_profiles profile_photo failed: " . $e->getMessage());
            }

            if (!$updated) {
                // fallback to employees table
                try {
                    $stmt = $db->prepare("UPDATE employees SET profile_photo = ? WHERE employee_id = ?");
                    $stmt->execute([$filepath, $employee_id]);
                    if ($stmt->rowCount() > 0) $updated = true;
                } catch (Exception $e) {
                    error_log("Update employees profile_photo failed: " . $e->getMessage());
                }
            }

            // Fetch name for logging
            $stmt = $db->prepare("SELECT firstname, lastname FROM staff_profiles WHERE employee_id = ?");
            $stmt->execute([$employee_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$employee) {
                $stmt = $db->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ?");
                $stmt->execute([$employee_id]);
                $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            $first = $employee['first_name'] ?? ($employee['firstname'] ?? 'Unknown');
            $last = $employee['last_name'] ?? '';

            // Log photo upload
            $logMessage = "Profile photo updated for employee: {$first} {$last} (ID: $employee_id)";
            logSystemActivity($db, 'profile_photo_update', $logMessage, $employee_id, json_encode([
                'filename' => $filename,
                'filepath' => $filepath,
                'file_size' => $file['size'],
                'file_type' => $file['type']
            ]));

            echo json_encode(['success' => true, 'newPhoto' => $filepath]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to upload file']);
        }

    } catch (Exception $e) {
        error_log("Photo upload error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'System error occurred']);
    }
    exit;
}

// Default response for other requests
echo json_encode(['success' => false, 'error' => 'Invalid request']);
exit;

// -----------------------------
// Helper: sendPasswordChangeConfirmation
// Uses PHPMailer if available. Falls back to internal logger if not.
// -----------------------------
function sendPasswordChangeConfirmation($db, $employeeCode) {
    // Get employee email and firstname
    $stmt = $db->prepare("SELECT email, firstname FROM staff_profiles WHERE employee_code = ?");
    $stmt->execute([$employeeCode]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        // fallback to employees (employee_code may be id)
        $stmt = $db->prepare("SELECT email, first_name FROM employees WHERE employee_id = ?");
        $stmt->execute([$employeeCode]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$employee || empty($employee['email'])) return;

    $firstname = $employee['firstname'] ?? ($employee['first_name'] ?? 'User');
    $to = $employee['email'];

    $subject = 'Password Changed - Fortishield Matrix';
    $message = "Hello {$firstname},

Your password was successfully changed.

Date: " . date('Y-m-d H:i:s') . "
IP Address: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "

If you did not make this change, please contact your system administrator immediately.

Best regards,
Fortishield Matrix Security Team
";

    // Try to send via PHPMailer if installed
    try {
        if (file_exists(__DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php')) {
            require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
            require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';
            require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            // Minimal PHPMailer config. Replace with your SMTP details or use env configuration.
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            // NOTE: replace these credentials or move them to env / config file
            $mail->Username = 'fortishieldmatrix@gmail.com';
            $mail->Password = 'wdzjmuwsjgzeswao';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;
            $mail->setFrom('fortishieldmatrix@gmail.com', 'Fortishield Matrix');
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = nl2br($message);
            $mail->isHTML(true);
            $mail->send();
            return true;
        } else {
            // PHPMailer not available; fallback to internal logger or sendEmailNotification
            sendEmailNotification($to, $subject, $message);
            return false;
        }
    } catch (Exception $e) {
        error_log('Password change confirmation email error: ' . ($mail->ErrorInfo ?? $e->getMessage()));
        return false;
    }
}
