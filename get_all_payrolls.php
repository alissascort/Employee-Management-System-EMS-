<?php
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

try {
    $db = new Database();
    $conn = $db->connect();

    // Join payroll with employees to get names and codes
    $stmt = $conn->prepare("
        SELECT 
        p.payroll_id,
        p.employee_id,
        p.pay_period,
        p.basic_salary,
        p.allowances,
        p.deductions,
        p.net_salary,
        p.payment_date,
        s.firstname,
        s.lastname,
        s.employee_code
    FROM payroll p
    LEFT JOIN staff_profiles s ON p.employee_id = s.id
    ORDER BY p.payment_date DESC
");
    $stmt->execute();

    $payrolls = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $payrolls[] = [
            'employee_name'   => trim($row['firstname'] . ' ' . $row['lastname']),
            'employee_code'   => $row['employee_code'],
            'pay_period'      => $row['pay_period'],
            'basic_salary'    => $row['basic_salary'],
            'allowances'      => $row['allowances'],
            'deductions'      => $row['deductions'],
            'net_salary'      => $row['net_salary'],
            'date_processed'  => $row['payment_date']
        ];
    }

    echo json_encode(['success' => true, 'payrolls' => $payrolls]);
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'payrolls' => [], 'message' => 'Database error']);
}
?>
