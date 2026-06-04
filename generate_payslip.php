<?php
// MUST be first - no output before this
session_start();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="payslip.pdf"');
header('Cache-Control: private, must-revalidate');
header("Access-Control-Allow-Origin: http://localhost:8000");
header("Access-Control-Allow-Credentials: true");
var_dump($_SESSION);
die();

// Debugging - remove in production
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verify authentication FIRST
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.0 401 Unauthorized');
    die('Unauthorized access');
}

// Then validate input
$payrollId = filter_input(INPUT_GET, 'payroll_id', FILTER_VALIDATE_INT);
if (!$payrollId) {
    header('HTTP/1.0 400 Bad Request');
    die('Invalid payroll ID');
}

// Database connection
require_once 'db_connect.php';

// Fetch payroll data - FIXED TABLE NAME TYPO (staff_profilessession_start() was incorrect)
$stmt = $pdo->prepare("
    SELECT p.*, e.full_name, e.position, e.department, e.employee_code
    FROM payroll p
    JOIN staff_profiles e ON p.employee_id = e.id
    WHERE p.payroll_id = ? AND p.employee_id = ?
    LIMIT 1
");
$stmt->execute([$payrollId, $_SESSION['user_id']]);
$payroll = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payroll) {
    header('HTTP/1.0 404 Not Found');
    die('Payslip not found');
}

// PDF Generation
require 'vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);

// HTML template
$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        /* Your CSS styles here */
    </style>
</head>
<body>
    <!-- Your HTML content here -->
</body>
</html>
HTML;

// Generate PDF
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Clear output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Output PDF
echo $dompdf->output();
exit;
?>
