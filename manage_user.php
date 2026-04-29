<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
session_start();

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Only admin can manage users
if ($user_type !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? '';

try {
    $db = new Database();
    $pdo = $db->connect();
    
    switch ($action) {
        case 'add':
            // Validate required fields
            $required_fields = ['first_name', 'last_name', 'email', 'user_type', 'password'];
            foreach ($required_fields as $field) {
                if (empty($input[$field])) {
                    echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
                    exit;
                }
            }
            
            // Validate password length
            if (strlen($input['password']) < 8) {
                echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters']);
                exit;
            }
            
            // Hash password
            $hashed_password = password_hash($input['password'], PASSWORD_DEFAULT);
            
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE email = ?");
            $stmt->execute([$input['email']]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Email already exists']);
                exit;
            }
            
            // Generate employee code for employees
            $employee_code = null;
            if ($input['user_type'] === 'employee') {
                $year = date('Y');
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_code LIKE ?");
                $stmt->execute(["$year/EMP/%"]);
                $count = $stmt->fetchColumn() + 1;
                $employee_code = "$year/EMP/" . str_pad($count, 4, '0', STR_PAD_LEFT);
            }
            
            // Insert user based on type
            switch ($input['user_type']) {
                case 'employee':
                    $stmt = $pdo->prepare("INSERT INTO employees (first_name, last_name, email, employee_code, department, position, phone, status, password_hash, hire_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $input['first_name'],
                        $input['last_name'],
                        $input['email'],
                        $employee_code,
                        $input['department'] ?? '',
                        $input['position'] ?? '',
                        $input['phone'] ?? '',
                        $input['status'] ?? 'active',
                        $hashed_password
                    ]);
                    $new_user_id = $pdo->lastInsertId();
                    break;
                    
                case 'admin':
                    $stmt = $pdo->prepare("INSERT INTO admins (full_name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
                    $stmt->execute([
                        $input['first_name'] . ' ' . $input['last_name'],
                        $input['email'],
                        $hashed_password
                    ]);
                    $new_user_id = $pdo->lastInsertId();
                    break;
                    
                case 'dept_manager':
                    $stmt = $pdo->prepare("INSERT INTO dept_managers (full_name, email, department, password_hash, role) VALUES (?, ?, ?, ?, 'dept_manager')");
                    $stmt->execute([
                        $input['first_name'] . ' ' . $input['last_name'],
                        $input['email'],
                        $input['department'] ?? '',
                        $hashed_password
                    ]);
                    $new_user_id = $pdo->lastInsertId();
                    break;
                    
                case 'hr':
                    $stmt = $pdo->prepare("INSERT INTO hr (full_name, email, password_hash, role) VALUES (?, ?, ?, 'hr')");
                    $stmt->execute([
                        $input['first_name'] . ' ' . $input['last_name'],
                        $input['email'],
                        $hashed_password
                    ]);
                    $new_user_id = $pdo->lastInsertId();
                    break;
                    
                case 'cso':
                    $stmt = $pdo->prepare("INSERT INTO csos (full_name, email, password_hash, role) VALUES (?, ?, ?, 'cso')");
                    $stmt->execute([
                        $input['first_name'] . ' ' . $input['last_name'],
                        $input['email'],
                        $hashed_password
                    ]);
                    $new_user_id = $pdo->lastInsertId();
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid user type']);
                    exit;
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'User added successfully',
                'user_id' => $new_user_id,
                'employee_code' => $employee_code
            ]);
            break;
            
        case 'edit':
            // Validate required fields
            if (empty($input['user_id']) || empty($input['user_type'])) {
                echo json_encode(['success' => false, 'message' => 'User ID and type are required']);
                exit;
            }
            
            $user_id_to_edit = $input['user_id'];
            $user_type_to_edit = $input['user_type'];
            
            // Update user based on type
            switch ($user_type_to_edit) {
                case 'employee':
                    $stmt = $pdo->prepare("UPDATE employees SET first_name = ?, last_name = ?, email = ?, department = ?, position = ?, phone = ?, status = ? WHERE employee_id = ?");
                    $stmt->execute([
                        $input['first_name'],
                        $input['last_name'],
                        $input['email'],
                        $input['department'] ?? '',
                        $input['position'] ?? '',
                        $input['phone'] ?? '',
                        $input['status'] ?? 'active',
                        $user_id_to_edit
                    ]);
                    break;
                    
                case 'admin':
                    $stmt = $pdo->prepare("UPDATE admins SET full_name = ?, email = ? WHERE admin_id = ?");
                    $stmt->execute([
                        $input['first_name'] . ' ' . $input['last_name'],
                        $input['email'],
                        $user_id_to_edit
                    ]);
                    break;
                    
                case 'dept_manager':
                    $stmt = $pdo->prepare("UPDATE dept_managers SET full_name = ?, email = ?, department = ? WHERE manager_id = ?");
                    $stmt->execute([
                        $input['first_name'] . ' ' . $input['last_name'],
                        $input['email'],
                        $input['department'] ?? '',
                        $user_id_to_edit
                    ]);
                    break;
                    
                case 'hr':
                    $stmt = $pdo->prepare("UPDATE hr SET full_name = ?, email = ? WHERE hr_id = ?");
                    $stmt->execute([
                        $input['first_name'] . ' ' . $input['last_name'],
                        $input['email'],
                        $user_id_to_edit
                    ]);
                    break;
                    
                case 'cso':
                    $stmt = $pdo->prepare("UPDATE csos SET full_name = ?, email = ? WHERE cso_id = ?");
                    $stmt->execute([
                        $input['first_name'] . ' ' . $input['last_name'],
                        $input['email'],
                        $user_id_to_edit
                    ]);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid user type']);
                    exit;
            }
            
            // Update password if provided
            if (!empty($input['password'])) {
                if (strlen($input['password']) < 8) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters']);
                    exit;
                }
                
                $hashed_password = password_hash($input['password'], PASSWORD_DEFAULT);
                
                switch ($user_type_to_edit) {
                    case 'employee':
                        $stmt = $pdo->prepare("UPDATE employees SET password_hash = ? WHERE employee_id = ?");
                        break;
                    case 'admin':
                        $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE admin_id = ?");
                        break;
                    case 'dept_manager':
                        $stmt = $pdo->prepare("UPDATE dept_managers SET password_hash = ? WHERE manager_id = ?");
                        break;
                    case 'hr':
                        $stmt = $pdo->prepare("UPDATE hr SET password_hash = ? WHERE hr_id = ?");
                        break;
                                    case 'cso':
                    $stmt = $pdo->prepare("UPDATE csos SET password_hash = ? WHERE cso_id = ?");
                    break;
                }
                $stmt->execute([$hashed_password, $user_id_to_edit]);
            }
            
            echo json_encode(['success' => true, 'message' => 'User updated successfully']);
            break;
            
        case 'delete':
            if (empty($input['user_id']) || empty($input['user_type'])) {
                echo json_encode(['success' => false, 'message' => 'User ID and type are required']);
                exit;
            }
            
            $user_id_to_delete = $input['user_id'];
            $user_type_to_delete = $input['user_type'];
            
            // Don't allow deleting self
            if ($user_id_to_delete == $user_id) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
                exit;
            }
            
            // Delete user based on type
            switch ($user_type_to_delete) {
                case 'employee':
                    $stmt = $pdo->prepare("DELETE FROM employees WHERE employee_id = ?");
                    break;
                case 'admin':
                    $stmt = $pdo->prepare("DELETE FROM admins WHERE admin_id = ?");
                    break;
                case 'dept_manager':
                    $stmt = $pdo->prepare("DELETE FROM dept_managers WHERE manager_id = ?");
                    break;
                case 'hr':
                    $stmt = $pdo->prepare("DELETE FROM hr WHERE hr_id = ?");
                    break;
                case 'cso':
                    $stmt = $pdo->prepare("DELETE FROM csos WHERE cso_id = ?");
                    break;
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid user type']);
                    exit;
            }
            
            $stmt->execute([$user_id_to_delete]);
            
            echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?> 