<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';

class EmployeeManager {
    private $conn;
    private $table_name = "employees";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function addEmployee($employeeData) {
        try {
            // Validate required fields
            $required = [
                'employee_id', 'first_name', 'last_name', 'email', 
                'department_id', 'position_id', 'hire_date'
            ];
            
            foreach ($required as $field) {
                if (empty($employeeData[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Validate email
            if (!filter_var($employeeData['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }
            
            // Check if employee ID already exists
            if ($this->employeeIdExists($employeeData['employee_id'])) {
                throw new Exception("Employee ID already exists");
            }
            
            // Check if email already exists
            if ($this->emailExists($employeeData['email'])) {
                throw new Exception("Email already exists");
            }
            
            // Generate username
            $username = $this->generateUsername($employeeData['first_name'], $employeeData['last_name']);
            
            // Generate temporary password
            $tempPassword = $this->generateTempPassword();
            $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
            
            // Begin transaction
            $this->conn->beginTransaction();
            
            try {
                // Insert into employees table
                $employeeId = $this->insertEmployee($employeeData, $username, $hashedPassword);
                
                // Create user account
                $userId = $this->createUserAccount($employeeData, $username, $hashedPassword, $employeeId);
                
                // Assign default role
                $this->assignUserRole($userId, 'employee');
                
                // Create employee profile
                $this->createEmployeeProfile($employeeId, $employeeData);
                
                // Set up initial leave balance
                $this->setupLeaveBalance($employeeId);
                
                // Create onboarding checklist
                $this->createOnboardingChecklist($employeeId);
                
                // Send welcome email
                $this->sendWelcomeEmail($employeeData, $username, $tempPassword);
                
                // Commit transaction
                $this->conn->commit();
                
                // Log employee creation
                $this->logEmployeeActivity($employeeId, 'EMPLOYEE_CREATED', 'New employee added to system');
                
                return [
                    'success' => true,
                    'message' => 'Employee added successfully',
                    'employee_id' => $employeeId,
                    'user_id' => $userId,
                    'username' => $username,
                    'temp_password' => $tempPassword,
                    'onboarding_status' => 'initiated'
                ];
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw new Exception("Failed to add employee: " . $e->getMessage());
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function employeeIdExists($employeeId) {
        $query = "SELECT employee_id FROM {$this->table_name} WHERE employee_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$employeeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
    
    private function emailExists($email) {
        $query = "SELECT email FROM {$this->staff_profiles} WHERE email = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
    
    private function generateUsername($firstName, $lastName) {
        $baseUsername = strtolower($firstName[0] . $lastName);
        $username = $baseUsername;
        $counter = 1;
        
        while ($this->usernameExists($username)) {
            $username = $baseUsername . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    private function usernameExists($username) {
        $query = "SELECT username FROM users WHERE username = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$username]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
    
    private function generateTempPassword($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
    
    private function insertEmployee($data, $username, $hashedPassword) {
        $query = "INSERT INTO {$this->staff_profiles} 
                 (employee_id, first_name, last_name, email, phone, date_of_birth, 
                  gender, national_id, passport_number, address, city, state, 
                  postal_code, country, emergency_contact_name, emergency_contact_phone,
                  emergency_contact_relation, department_id, position_id, hire_date, 
                  employment_type, salary, bank_name, bank_account_number, 
                  tax_id, social_security_number, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $data['employee_id'],
            $data['first_name'],
            $data['last_name'],
            $data['email'],
            $data['phone'] ?? null,
            $data['date_of_birth'] ?? null,
            $data['gender'] ?? null,
            $data['national_id'] ?? null,
            $data['passport_number'] ?? null,
            $data['address'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['postal_code'] ?? null,
            $data['country'] ?? null,
            $data['emergency_contact_name'] ?? null,
            $data['emergency_contact_phone'] ?? null,
            $data['emergency_contact_relation'] ?? null,
            $data['department_id'],
            $data['position_id'],
            $data['hire_date'],
            $data['employment_type'] ?? 'full_time',
            $data['salary'] ?? null,
            $data['bank_name'] ?? null,
            $data['bank_account_number'] ?? null,
            $data['tax_id'] ?? null,
            $data['social_security_number'] ?? null
        ]);
        
        return $this->conn->lastInsertId();
    }
    
    private function createUserAccount($data, $username, $hashedPassword, $employeeId) {
        $query = "INSERT INTO employees
                 (username, email, password, full_name, employee_id, department_id, 
                  position_id, status, email_verified, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, NOW())";
        
        $fullName = $data['first_name'] . ' ' . $data['last_name'];
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $username,
            $data['email'],
            $hashedPassword,
            $fullName,
            $employeeId,
            $data['department_id'],
            $data['position_id']
        ]);
        
        return $this->conn->lastInsertId();
    }
    
    private function assignUserRole($userId, $role) {
        $query = "INSERT INTO user_roles (user_id, role_id, assigned_at) 
                 VALUES (?, (SELECT role_id FROM roles WHERE role_name = ?), NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$userId, $role]);
    }
    
    private function createEmployeeProfile($employeeId, $data) {
        $query = "INSERT INTO staff_profiles 
                 (employee_id, bio, skills, qualifications, certifications,
                  experience_years, education_level, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $employeeId,
            $data['bio'] ?? '',
            $data['skills'] ?? '',
            $data['qualifications'] ?? '',
            $data['certifications'] ?? '',
            $data['experience_years'] ?? 0,
            $data['education_level'] ?? ''
        ]);
    }
    
    private function setupLeaveBalance($employeeId) {
        $leaveTypes = ['Vacation', 'Sick Leave', 'Personal Leave', 'Maternity/Paternity'];
        
        foreach ($leaveTypes as $type) {
            $query = "INSERT INTO leave_balances
                     (employee_id, leave_type, total_days, used_days, remaining_days, fiscal_year)
                     VALUES (?, ?, ?, 0, ?, YEAR(CURDATE()))";
            
            $totalDays = $this->getDefaultLeaveDays($type);
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$employeeId, $type, $totalDays, $totalDays]);
        }
    }
    
    private function getDefaultLeaveDays($leaveType) {
        $defaults = [
            'Annual Leave' => 28,
            'Sick Leave' => 14,
            'Emergency Leave' => 7,
            'Maternity/Paternity' => 90
        ];
        
        return $defaults[$leaveType] ?? 0;
    }
    
    private function createOnboardingChecklist($employeeId) {
        $onboardingTasks = [
            'Create Email Account',
            'Setup System Access',
            'Assign Workstation',
            'Provide Employee Handbook',
            'Schedule Orientation',
            'Assign Mentor',
            'Setup Benefits',
            'Issue Access Card',
            'Provide Equipment',
            'Complete Tax Forms'
        ];
        
        foreach ($onboardingTasks as $task) {
            $query = "INSERT INTO employee_onboarding 
                     (employee_id, task_name, status, due_date, assigned_to, created_at)
                     VALUES (?, ?, 'pending', DATE_ADD(CURDATE(), INTERVAL 7 DAY), NULL, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$employeeId, $task]);
        }
    }
    
    private function sendWelcomeEmail($employeeData, $username, $tempPassword) {
        // Implementation for sending welcome email
        // This would integrate with your email system
        
        $to = $employeeData['email'];
        $subject = "Welcome to Our Company - Account Details";
        $message = "
        Dear {$employeeData['first_name']} {$employeeData['last_name']},
        
        Welcome to our team! Your employee account has been created.
        
        Login Details:
        Username: $username
        Temporary Password: $tempPassword
        Login URL: http://localhost:8000/FSM.ESM.FRONT.1.html
        
        Please change your password after first login.
        
        Best regards,
        HR Department
        ";
        
        // In a real implementation, you would use PHPMailer or similar
        // mail($to, $subject, $message);
        
        error_log("Welcome email would be sent to: $to");
    }
    
    private function logEmployeeActivity($employeeId, $action, $details) {
        $query = "INSERT INTO employee_activity_log 
                 (employee_id, action, details, created_at)
                 VALUES (?, ?, ?, NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$employeeId, $action, $details]);
    }
}

// Process request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    $employeeManager = new EmployeeManager($db);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($input) {
        $result = $employeeManager->addEmployee($input);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    }
}
?>