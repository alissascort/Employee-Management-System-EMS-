<?php
error_log('add_employee.php called');
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();
session_start();
header("Content-Type: application/json");

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    error_log('Unauthorized access attempt to add_employee.php');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';

$response = ['success' => false, 'message' => 'An error occurred'];

try {
    $db = new Database();
    $conn = $db->connect();

    // Check if this is an edit operation
    $isEdit = isset($_POST['action']) && $_POST['action'] === 'edit';
    $employeeId = $isEdit ? intval($_POST['employee_id']) : null;

    if ($isEdit && !$employeeId) {
        error_log('Edit employee failed: Employee ID is required');
        $response['message'] = 'Employee ID is required for editing';
        echo json_encode($response);
        exit;
    }

    // Validate required fields (password not required for edit)
    $required_fields = ['first_name', 'last_name', 'email', 'department', 'position'];
    if (!$isEdit) {
        $required_fields[] = 'password'; // Password only required for new employees
    }
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $msg = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            error_log(($isEdit ? 'Edit' : 'Add') . ' employee failed: ' . $msg);
            $response['message'] = $msg;
            echo json_encode($response);
            exit;
        }
    }

    // Validate email format
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        error_log('Add employee failed: Invalid email format');
        $response['message'] = 'Invalid email format';
        echo json_encode($response);
        exit;
    }

    if ($isEdit) {
        // For edit: check if employee exists and get their details
        $stmt = $conn->prepare("SELECT e.employee_id, e.employee_code, sp.id as staff_profile_id 
                               FROM employees e 
                               LEFT JOIN staff_profiles sp ON e.employee_id = sp.employee_id 
                               WHERE e.employee_id = ?");
        $stmt->execute([$employeeId]);
        $existingEmployee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingEmployee) {
            error_log('Edit employee failed: Employee not found');
            $response['message'] = 'Employee not found';
            echo json_encode($response);
            exit;
        }
        
        // Check if email is being changed and if new email already exists
        if ($_POST['email'] !== $existingEmployee['email']) {
            $stmt = $conn->prepare("SELECT employee_id FROM employees WHERE email = ? AND employee_id != ?");
            $stmt->execute([$_POST['email'], $employeeId]);
            if ($stmt->fetch()) {
                error_log('Edit employee failed: Email already exists');
                $response['message'] = 'Email already exists';
                echo json_encode($response);
                exit;
            }
        }
    } else {
        // For add: check if email already exists in employees table (this is expected for existing employees)
        $stmt = $conn->prepare("SELECT employee_id, employee_code FROM employees WHERE email = ?");
        $stmt->execute([$_POST['email']]);
        $existingEmployee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingEmployee) {
            error_log('Add employee failed: Employee not found in primary registration.');
            $response['message'] = 'Employee not found in primary registration. Employee must register themselves first.';
            echo json_encode($response);
            exit;
        }
        
        // Check if staff profile already exists for this employee
        $stmt = $conn->prepare("SELECT id FROM staff_profiles WHERE employee_code = ?");
        $stmt->execute([$existingEmployee['employee_code']]);
        if ($stmt->fetch()) {
            error_log('Add employee failed: Staff profile already exists for this employee.');
            $response['message'] = 'Staff profile already exists for this employee.';
            echo json_encode($response);
            exit;
        }
    }

    if ($isEdit) {
        // For edit: use existing employee code
        $employeeCode = $existingEmployee['employee_code'];
        
        // Only hash password if it's provided
        $passwordHash = null;
        if (!empty($_POST['password'])) {
            $passwordHash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }
    } else {
        // For add: generate employee code for reference
        $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(employee_code, 2) AS UNSIGNED)) as max_num FROM employees WHERE employee_code LIKE 'E%'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextNum = ($result['max_num'] ?? 0) + 1;
        $employeeCode = 'E' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        // Hash password for admin functions (forgot-password mechanism, etc.)
        $passwordHash = password_hash($_POST['password'], PASSWORD_DEFAULT);
    }

    // Handle profile photo upload
    $profilePhotoPath = null;
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 2 * 1024 * 1024; // 2MB

        $file_type = mime_content_type($_FILES['profile_photo']['tmp_name']);
        $file_size = $_FILES['profile_photo']['size'];

        if (!in_array($file_type, $allowed_types) || $file_size > $max_size) {
            error_log('Add employee failed: Invalid image type or size');
            $response['message'] = 'Invalid image type or size (JPG, PNG, max 2MB)';
            echo json_encode($response);
            exit;
        }

        $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $file_name = 'employee_' . $employeeCode . '_' . uniqid() . '.' . $ext;
        $upload_dir = __DIR__ . '/uploads/';

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $target_path = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $target_path)) {
            $profilePhotoPath = 'uploads/' . $file_name;
        }
    }



    if ($isEdit) {
        // For edit: update existing records
        error_log('Attempting to update employee for employee_code: ' . $existingEmployee['employee_code']);
        
        // Update employees table
        $updateFields = ['first_name = ?', 'last_name = ?', 'email = ?', 'department = ?', 'position = ?'];
        $updateValues = [
            trim($_POST['first_name']),
            trim($_POST['last_name']),
            trim($_POST['email']),
            $_POST['department'],
            $_POST['position']
        ];
        
        if ($passwordHash) {
            $updateFields[] = 'password_hash = ?';
            $updateValues[] = $passwordHash;
        }
        
        $updateValues[] = $employeeId; // For WHERE clause
        
        $stmt = $conn->prepare("UPDATE employees SET " . implode(', ', $updateFields) . " WHERE employee_id = ?");
        $stmt->execute($updateValues);
        
        // Update staff_profiles table if it exists
        if ($existingEmployee['staff_profile_id']) {
            $stmt = $conn->prepare("
                UPDATE staff_profiles SET 
                    firstname = ?, lastname = ?, email = ?, department = ?, role = ?,
                    address = ?, country = ?, state = ?, city = ?, date_of_birth = ?
                WHERE id = ?
            ");
            $stmt->execute([
                trim($_POST['first_name']),
                trim($_POST['last_name']),
                trim($_POST['email']),
                $_POST['department'],
                $_POST['position'],
                $_POST['address'] ?? '',
                $_POST['country'] ?? 'Tanzania',
                $_POST['state'] ?? '',
                $_POST['city'] ?? '',
                $_POST['date_of_birth'] ?? null,
                $existingEmployee['staff_profile_id']
            ]);
        }
        
        error_log('Employee update SUCCESS for employee_code: ' . $existingEmployee['employee_code']);
        $response = [
            'success' => true,
            'message' => 'Employee updated successfully',
            'employee_code' => $existingEmployee['employee_code']
        ];
    } else {
        // For add: insert new staff profile
        // Update employee password in employees table (for admin functions)
        $stmt = $conn->prepare("UPDATE employees SET password_hash = ? WHERE employee_code = ?");
        $stmt->execute([$passwordHash, $existingEmployee['employee_code']]);

        // Before the INSERT
        error_log('Attempting to insert staff profile for employee_code: ' . $existingEmployee['employee_code']);

        // Insert into staff_profiles table (admin adds additional information)
        $stmt = $conn->prepare("
            INSERT INTO staff_profiles (
                employee_id, employee_code, firstname, lastname, email, 
                department, role, address, country, state, city, 
                date_of_birth, registration_date, profile_photo, status
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'Active'
            )
        ");

        $insertData = [
            $existingEmployee['employee_id'],
            $existingEmployee['employee_code'],
            trim($_POST['first_name']),
            trim($_POST['last_name']),
            trim($_POST['email']),
            $_POST['department'],
            $_POST['position'],
            $_POST['address'] ?? '',
            $_POST['country'] ?? 'Tanzania',
            $_POST['state'] ?? '',
            $_POST['city'] ?? '',
            $_POST['date_of_birth'] ?? null,
            $profilePhotoPath
        ];
        error_log('Insert data: ' . print_r($insertData, true));
        $stmt->execute($insertData);

        if ($stmt->rowCount() > 0) {
            error_log('Staff profile insert SUCCESS for employee_code: ' . $existingEmployee['employee_code']);
            $response = [
                'success' => true,
                'message' => 'Staff profile added successfully for existing employee',
                'employee_code' => $existingEmployee['employee_code']
            ];
        } else {
            error_log('Staff profile insert FAILED for employee_code: ' . $existingEmployee['employee_code']);
            $response['message'] = 'Failed to add staff profile';
        }
    }

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $response['message'] = 'Database error occurred: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $response['message'] = 'An error occurred';
}

echo json_encode($response);
?>