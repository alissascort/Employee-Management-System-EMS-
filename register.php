<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Remove JSON input handling
// $input = json_decode(file_get_contents('php://input'), true);

// Initialize response array
$response = ['success' => false, 'message' => 'An error occurred', 'role' => ''];

// Remove JSON decoding validation
// if (json_last_error() !== JSON_ERROR_NONE) {
//     $response['message'] = 'Invalid JSON data';
//     echo json_encode($response);
//     exit;
// }

// Validate required fields
$requiredFields = ['email', 'password', 'confirm_password', 'user_type'];
foreach ($requiredFields as $field) {
    if (empty($_POST[$field])) {
        $response['message'] = 'Missing required fields';
        echo json_encode($response);
        exit;
    }
}

$email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
$password = $_POST['password'];
$confirmPassword = $_POST['confirm_password'];
$userType = $_POST['user_type'];

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $response['message'] = 'Invalid email format';
    echo json_encode($response);
    exit;
}

// Validate password
if (strlen($password) < 8) {
    $response['message'] = 'Password must be at least 8 characters';
    echo json_encode($response);
    exit;
}

if ($password !== $confirmPassword) {
    $response['message'] = 'Passwords do not match';
    echo json_encode($response);
    exit;
}

// Additional validation based on user type
if ($userType === 'employee') {
    $employeeFields = ['first_name', 'last_name', 'department', 'position', 'hire_date'];
    foreach ($employeeFields as $field) {
        if (empty($_POST[$field])) {
            $response['message'] = 'Missing employee information: ' . $field;
            echo json_encode($response);
            exit;
        }
    }
} elseif (in_array($userType, ['admin', 'cso', 'hr']) && empty($_POST['full_name'])) {
    $response['message'] = 'Full name is required';
    echo json_encode($response);
    exit;
} elseif ($userType === 'Dept Manager') {
    if (empty($_POST['full_name'])) {
        $response['message'] = 'Full name is required';
        echo json_encode($response);
        exit;
    }
    if (empty($_POST['department'])) {
        $response['message'] = 'Department is required for Department Manager';
        echo json_encode($response);
        exit;
    }
}

// Handle profile photo upload
$profilePhotoPath = null;
if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
    $allowedTypes = ['image/jpeg', 'image/png'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    $fileType = mime_content_type($_FILES['profile_photo']['tmp_name']);
    $fileSize = $_FILES['profile_photo']['size'];
    if (!in_array($fileType, $allowedTypes) || $fileSize > $maxSize) {
        $response['message'] = 'Invalid image type or size (JPG, PNG, max 2MB)';
        echo json_encode($response);
        exit;
    }
    $ext = $fileType === 'image/png' ? '.png' : '.jpg';
    $fileName = uniqid('profile_', true) . $ext;
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    $targetPath = $uploadDir . $fileName;
    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
        $profilePhotoPath = 'uploads/' . $fileName;
    } else {
        $response['message'] = 'Failed to upload profile photo';
        echo json_encode($response);
        exit;
    }
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Allow up to 2 admins or CSOs for redundancy/backup
    if ($userType === 'admin') {
        $check = $conn->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        if ($check >= 2) {
            $response['message'] = 'Maximum of 2 admins allowed for redundancy';
            echo json_encode($response);
            exit;
        }
    }
    if ($userType === 'cso') {
        $check = $conn->query("SELECT COUNT(*) FROM csos")->fetchColumn();
        if ($check >= 2) {
            $response['message'] = 'Maximum of 2 CSOs allowed for redundancy';
            echo json_encode($response);
            exit;
        }
    }
    if ($userType === 'hr') {
        $check = $conn->query("SELECT COUNT(*) FROM hr")->fetchColumn();
        if ($check >= 2) {
            $response['message'] = 'Maximum of 2 HR staff allowed for redundancy';
            echo json_encode($response);
            exit;
        }
    }
    
    // Check if email already exists
   $checkStmt = $conn->prepare("SELECT email FROM employees WHERE email = ? 
                            UNION SELECT email FROM admins WHERE email = ? 
                            UNION SELECT email FROM csos WHERE email = ?
                            UNION SELECT email FROM hr WHERE email = ?
                            UNION SELECT email FROM dept_managers WHERE email = ?");
$checkStmt->execute([$email, $email, $email, $email, $email]);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() > 0) {
        $response['message'] = 'Email already registered';
        echo json_encode($response);
        exit;
    }
    
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    // Store values in variables before binding
    $fullName = $_POST['full_name'] ?? '';
    $firstName = $_POST['first_name'] ?? '';
    $lastName = $_POST['last_name'] ?? '';
    $department = $_POST['department'] ?? '';
    $position = $_POST['position'] ?? '';
    $hireDate = $_POST['hire_date'] ?? '';
    $phone = $_POST['phone'] ?? '';
    
    // Insert based on user type
    switch ($userType) {
        case 'admin':
            $stmt = $conn->prepare("INSERT INTO admins (email, password_hash, full_name, profile_photo) 
                                  VALUES (:email, :password, :full_name, :profile_photo)");
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $passwordHash);
            $stmt->bindParam(':full_name', $fullName);
            $stmt->bindParam(':profile_photo', $profilePhotoPath);
            break;
            
        case 'employee':
            $employeeCode = date('Y') . '/EMP/' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare("INSERT INTO employees 
                (employee_code, first_name, last_name, email, password_hash, department, position, hire_date, phone, status, role, profile_photo) 
                VALUES (:code, :first_name, :last_name, :email, :password, :dept, :position, :hire_date, :phone, 'active', 'employee', :profile_photo)");
            
            $stmt->bindParam(':code', $employeeCode);
            $stmt->bindParam(':first_name', $firstName);
            $stmt->bindParam(':last_name', $lastName);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $passwordHash);
            $stmt->bindParam(':dept', $department);
            $stmt->bindParam(':position', $position);
            $stmt->bindParam(':hire_date', $hireDate);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':profile_photo', $profilePhotoPath);
            break;
            
        case 'cso':
            $csoCode = date('Y') . '/CSO/' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare("INSERT INTO csos (cso_code, email, password_hash, full_name, profile_photo) 
                                  VALUES (:cso_code, :email, :password, :full_name, :profile_photo)");
            $stmt->bindParam(':cso_code', $csoCode);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $passwordHash);
            $stmt->bindParam(':full_name', $fullName);
            $stmt->bindParam(':profile_photo', $profilePhotoPath);
            break;
            
        case 'hr':
            $hrCode = date('Y') . '/HR/' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare("INSERT INTO hr (hr_code, email, password_hash, full_name, profile_photo) 
                                  VALUES (:hr_code, :email, :password, :full_name, :profile_photo)");
            $stmt->bindParam(':hr_code', $hrCode);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $passwordHash);
            $stmt->bindParam(':full_name', $fullName);
            $stmt->bindParam(':profile_photo', $profilePhotoPath);
            break;
            
        case 'Dept Manager':
            $dmCode = date('Y') . '/DM/' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare("INSERT INTO dept_managers (dm_code, email, password_hash, full_name, department, profile_photo) 
                                  VALUES (:dm_code, :email, :password, :full_name, :department, :profile_photo)");
            $stmt->bindParam(':dm_code', $dmCode);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $passwordHash);
            $stmt->bindParam(':full_name', $fullName);
            $stmt->bindParam(':department', $department);
            $stmt->bindParam(':profile_photo', $profilePhotoPath);
            break;
            
        default:
            $response['message'] = 'Invalid user type';
            echo json_encode($response);
            exit;
    }
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Registration successful';
        $response['role'] = $userType;
        // All registrations are handled in the main switch case above
        // No need for duplicate inserts
        if ($userType === 'hr') {
            // HR registration is already handled in the main switch case above
            // No need for duplicate insert - HR data is already in hr table
            // The role is already set to 'hr' in the main insert
        } elseif ($userType === 'cso') {
            // CSO registration is already handled in the main switch case above
            // No need for duplicate insert - CSO data is already in csos table
            // The role is already set to 'cso' in the main insert
        } elseif ($userType === 'Dept Manager') {
            // Dept Manager registration is already handled in the main switch case above
            // No need for duplicate insert - Dept Manager data is already in dept_managers table
            // The role is already set to 'Dept Manager' in the main insert
        }
    } else {
        $response['message'] = 'Registration failed';
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $response['message'] = 'Database error occurred';
}

echo json_encode($response);
?>

