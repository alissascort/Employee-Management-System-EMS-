```php
<?php
session_start();
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Check if employee is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'employee') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate input
$requiredFields = ['leave_type', 'start_date', 'end_date', 'reason'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

$leaveType = $input['leave_type'];
$startDate = $input['start_date'];
$endDate = $input['end_date'];
$reason = $input['reason'];

if (!strtotime($startDate) || !strtotime($endDate)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

if (strtotime($startDate) > strtotime($endDate)) {
    echo json_encode(['success' => false, 'message' => 'Start date must be before end date']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();

    // ========================
    // YEARLY RESET SECTION
    // ========================
    $currentYear = date("Y");
    $lastYear = $currentYear - 1;

    // Reset balances on first access each year
    $resetStmt = $conn->prepare("SELECT COUNT(*) FROM leave_balances WHERE year = :year");
    $resetStmt->execute([':year' => $currentYear]);
    $exists = $resetStmt->fetchColumn();

    if (!$exists) {
        // Carry forward unused annual leave from last year
        $carryStmt = $conn->prepare("SELECT * FROM leave_balances WHERE year = :lastYear");
        $carryStmt->execute([':lastYear' => $lastYear]);
        $lastBalances = $carryStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lastBalances as $bal) {
            $unused = ($bal['annual_entitlement'] + $bal['carry_forward']) - $bal['annual_taken'];
            $carry = $unused > 0 ? $unused : 0;

            $insertNew = $conn->prepare("INSERT INTO leave_balances 
                (employee_id, year, annual_entitlement, carry_forward, sick_entitlement, maternity_entitlement, emergency_entitlement) 
                VALUES (:id, :year, 28, :carry, 14, 90, 7)");
            $insertNew->execute([
                ':id' => $bal['employee_id'],
                ':year' => $currentYear,
                ':carry' => $carry
            ]);
        }
    }

    // ========================
    // EXISTING LEAVE LOGIC
    // ========================

    // Get employee details from employees table
    $employeeStmt = $conn->prepare("SELECT * FROM employees WHERE employee_id = :id");
    $employeeStmt->bindParam(':id', $_SESSION['user_id']);
    $employeeStmt->execute();
    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit;
    }

    // Get staff_profile id for foreign key relationship
    $staffStmt = $conn->prepare("SELECT id FROM staff_profiles WHERE employee_id = :id");
    $staffStmt->bindParam(':id', $_SESSION['user_id']);
    $staffStmt->execute();
    $staffProfile = $staffStmt->fetch(PDO::FETCH_ASSOC);

    if (!$staffProfile) {
        echo json_encode(['success' => false, 'message' => 'Staff profile not found']);
        exit;
    }

    $leaveDays = (strtotime($endDate) - strtotime($startDate)) / (60*60*24) + 1;
    $year = $currentYear;

    $conn->exec("CREATE TABLE IF NOT EXISTS leave_balances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        year INT NOT NULL,
        annual_entitlement INT DEFAULT 28,
        annual_taken INT DEFAULT 0,
        sick_entitlement INT DEFAULT 14,
        sick_taken INT DEFAULT 0,
        maternity_entitlement INT DEFAULT 90,
        maternity_taken INT DEFAULT 0,
        emergency_entitlement INT DEFAULT 7,
        emergency_taken INT DEFAULT 0,
        carry_forward INT DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Fetch or initialize balance row
    $balanceStmt = $conn->prepare("SELECT * FROM leave_balances WHERE employee_id = :id AND year = :year");
    $balanceStmt->execute([':id' => $employee['employee_id'], ':year' => $year]);
    $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);

    if (!$balance) {
        $initStmt = $conn->prepare("INSERT INTO leave_balances (employee_id, year) VALUES (:id, :year)");
        $initStmt->execute([':id' => $employee['employee_id'], ':year' => $year]);
        $balanceStmt->execute([':id' => $employee['employee_id'], ':year' => $year]);
        $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Validate against entitlement
    if ($leaveType === 'Annual') {
        $remaining = ($balance['annual_entitlement'] + $balance['carry_forward']) - $balance['annual_taken'];
        if ($leaveDays > $remaining) {
            echo json_encode(['success'=>false,'message'=>'Not enough annual leave days remaining']);
            exit;
        }
        $updateStmt = $conn->prepare("UPDATE leave_balances 
            SET annual_taken = annual_taken + :days 
            WHERE employee_id = :id AND year = :year");
        $updateStmt->execute([':days'=>$leaveDays, ':id'=>$employee['employee_id'], ':year'=>$year]);
    } elseif ($leaveType === 'Sick') {
        if ($leaveDays > ($balance['sick_entitlement'] - $balance['sick_taken'])) {
            echo json_encode(['success'=>false,'message'=>'Not enough sick leave days remaining']);
            exit;
        }
        $updateStmt = $conn->prepare("UPDATE leave_balances 
            SET sick_taken = sick_taken + :days 
            WHERE employee_id = :id AND year = :year");
        $updateStmt->execute([':days'=>$leaveDays, ':id'=>$employee['employee_id'], ':year'=>$year]);
    } elseif ($leaveType === 'Maternity') {
        if ($leaveDays > ($balance['maternity_entitlement'] - $balance['maternity_taken'])) {
            echo json_encode(['success'=>false,'message'=>'Not enough maternity leave days remaining']);
            exit;
        }
        $updateStmt = $conn->prepare("UPDATE leave_balances 
            SET maternity_taken = maternity_taken + :days 
            WHERE employee_id = :id AND year = :year");
        $updateStmt->execute([':days'=>$leaveDays, ':id'=>$employee['employee_id'], ':year'=>$year]);
    } elseif ($leaveType === 'Emergency') {
        if ($leaveDays > ($balance['emergency_entitlement'] - $balance['emergency_taken'])) {
            echo json_encode(['success'=>false,'message'=>'Not enough emergency leave days remaining']);
            exit;
        }
        $updateStmt = $conn->prepare("UPDATE leave_balances 
            SET emergency_taken = emergency_taken + :days 
            WHERE employee_id = :id AND year = :year");
        $updateStmt->execute([':days'=>$leaveDays, ':id'=>$employee['employee_id'], ':year'=>$year]);
    }

    // =========================
    // INSERT LEAVE REQUEST
    // =========================
    $stmt = $conn->prepare("INSERT INTO leave_requests 
    (employee_id, employee_name, leave_type, start_date, end_date, reason, status, department, email, processed_at, employee_code) 
    VALUES (:employee_id, :employee_name, :type, :start, :end, :reason, 'pending', :department, :email, NOW(), :employee_code)");
    $stmt->bindValue(':employee_id', $staffProfile['id']);
    $stmt->bindValue(':employee_name', $employee['first_name'] . ' ' . $employee['last_name']);
    $stmt->bindValue(':type', $leaveType);
    $stmt->bindValue(':start', $startDate);
    $stmt->bindValue(':end', $endDate);
    $stmt->bindValue(':reason', $reason);
    $stmt->bindValue(':department', $employee['department']);
    $stmt->bindValue(':email', $employee['email']);
    $stmt->bindValue(':employee_code', $employee['employee_code']);

    if ($stmt->execute()) {
        $leaveId = $conn->lastInsertId();
        $getStmt = $conn->prepare("SELECT * FROM leave_requests WHERE leave_id = :id");
        $getStmt->bindParam(':id', $leaveId);
        $getStmt->execute();
        $newLeave = $getStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Leave request submitted successfully',
            'leave_request' => $newLeave
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to submit leave request']);
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>

