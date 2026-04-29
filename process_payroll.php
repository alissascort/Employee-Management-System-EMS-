<?php
session_start();
header("Content-Type: application/json");

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';

$response = ['success' => false, 'message' => 'An error occurred'];

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Validate required fields
    $required_fields = ['employee_id', 'pay_period', 'basic_salary'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $response['message'] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            echo json_encode($response);
            exit;
        }
    }
    
    // Get form data
    $employee_id = $_POST['employee_id'];
    $pay_period = $_POST['pay_period'];
    $basic_salary = floatval($_POST['basic_salary']);
    $commission = floatval($_POST['commission'] ?? 0);
    $computer_allowance = floatval($_POST['computer_allowance'] ?? 0);
    $telephone_allowance = floatval($_POST['telephone_allowance'] ?? 0);
    $income_tax = floatval($_POST['income_tax'] ?? 0);
    $uif_contribution = floatval($_POST['uif_contribution'] ?? 0);
    
    // Calculate totals
    $total_allowances = $commission + $computer_allowance + $telephone_allowance;
    $total_deductions = $income_tax + $uif_contribution;
    $net_salary = $basic_salary + $total_allowances - $total_deductions;
    
    // Check if payroll already exists for this employee and period
    $stmt = $conn->prepare("
        SELECT id FROM payroll_records 
        WHERE employee_id = ? AND pay_period = ?
    ");
    $stmt->execute([$employee_id, $pay_period]);
    
    if ($stmt->fetch()) {
        $response['message'] = 'Payroll already exists for this employee and period';
        echo json_encode($response);
        exit;
    }
    
    // Insert payroll record
    $stmt = $conn->prepare("
        INSERT INTO payroll_records (
            employee_id, pay_period, basic_salary, allowances, deductions, 
            net_salary, pay_date, status, processed_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, CURDATE(), 'processed', NOW()
        )
    ");
    
    $stmt->execute([
        $employee_id,
        $pay_period,
        $basic_salary,
        $total_allowances,
        $total_deductions,
        $net_salary
    ]);
    
    if ($stmt->rowCount() > 0) {
        $response = [
            'success' => true,
            'message' => 'Payroll processed successfully',
            'payroll_id' => $conn->lastInsertId(),
            'net_salary' => $net_salary
        ];
    } else {
        $response['message'] = 'Failed to process payroll';
    }
    
} catch (PDOException $e) {
    error_log("Database Error in process_payroll.php: " . $e->getMessage());
    $response['message'] = 'Database error occurred: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Error in process_payroll.php: " . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

echo json_encode($response);
?>
