<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$payrollId = $_GET['payroll_id'] ?? null;

if (!$payrollId || !is_numeric($payrollId)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payroll ID']);
    exit;
}

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get payroll data with employee details
    $stmt = $conn->prepare("
        SELECT p.*, e.full_name AS employee_name, e.employee_code, e.position, e.department 
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_id
        WHERE p.payroll_id = :payroll_id AND p.employee_id = :employee_id
    ");
    
    $stmt->execute([
        ':payroll_id' => $payrollId,
        ':employee_id' => $_SESSION['user_id']
    ]);
    
    $payslipData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($payslipData) {
        echo json_encode([
            'success' => true,
            'payslip' => $payslipData
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Payslip not found or access denied'
        ]);
    }
    
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
