<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    error_log("SESSION user_id: " . $_SESSION['user_id']);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}
require_once 'db_connect.php';
$db = new Database();
$conn = $db->connect();

$stmt = $conn->prepare("
    SELECT 
        CONCAT(s.firstname, ' ', s.lastname) AS name,
        s.employee_code,
        p.pay_period,
        p.basic_salary,
        p.allowances,
        p.deductions,
        p.net_salary,
        p.payment_date AS date_processed
    FROM payroll p
    JOIN staff_profiles s ON p.employee_id = s.id
    WHERE p.employee_id = :employee_id
    ORDER BY p.payment_date DESC
");
$stmt->bindParam(':employee_id', $_SESSION['user_id']);
$stmt->execute();
$payroll = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'payroll' => $payroll]);
?>
