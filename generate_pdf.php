<?php
session_start();
require_once 'db_connect.php';
require_once 'vendor/autoload.php'; // Require Composer's autoloader for DomPDF
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

use Dompdf\Dompdf;
use Dompdf\Options;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized access');
}

$payrollId = $_GET['payroll_id'] ?? null;

if (!$payrollId || !is_numeric($payrollId)) {
    http_response_code(400);
    exit('Invalid payroll ID');
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
    
    if (!$payslipData) {
        http_response_code(404);
        exit('Payslip not found or access denied');
    }
    
    // Format currency
    function formatCurrency($value) {
        return '₦' . number_format($value, 2);
    }
    
    // Format date
    $paymentDate = date('F j, Y', strtotime($payslipData['payment_date']));
    $payPeriod = date('F Y', strtotime($payslipData['pay_period'] . '-01'));
    
    // Calculate values
    $basicSalary = (float)$payslipData['basic_salary'];
    $allowances = (float)$payslipData['allowances'];
    $deductions = (float)$payslipData['deductions'];
    $netSalary = (float)$payslipData['net_salary'];
    
    // Create HTML for PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <title>Payslip - ' . htmlspecialchars($payslipData['employee_name']) . ' - ' . $payPeriod . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; color: #333; }
            .payslip-container { max-width: 800px; margin: 0 auto; }
            .payslip-header { text-align: center; margin-bottom: 30px; }
            .payslip-employee-info { display: flex; justify-content: space-between; margin-bottom: 30px; }
            .payslip-details { display: flex; gap: 20px; margin-bottom: 30px; }
            .payslip-earnings, .payslip-deductions { flex: 1; padding: 15px; background: #f8f9fa; border-radius: 5px; }
            table { width: 100%; border-collapse: collapse; }
            td { padding: 5px 0; }
            .payslip-summary { background: #4361ee; color: white; padding: 15px; border-radius: 5px; text-align: center; }
            .payslip-footer { margin-top: 30px; font-size: 0.8rem; color: #666; text-align: center; }
            .text-right { text-align: right; }
        </style>
    </head>
    <body>
        <div class="payslip-container">
            <div class="payslip-header">
                <h2 style="color: #4361ee;">PAYSLIP</h2>
                <p style="font-size: 0.9rem; color: #666;">' . $paymentDate . '</p>
            </div>
            
            <div class="payslip-employee-info">
                <div>
                    <p><strong>Employee ID:</strong> ' . htmlspecialchars($payslipData['employee_code']) . '</p>
                    <p><strong>Name:</strong> ' . htmlspecialchars($payslipData['employee_name']) . '</p>
                    <p><strong>Position:</strong> ' . htmlspecialchars($payslipData['position']) . '</p>
                    <p><strong>Department:</strong> ' . htmlspecialchars($payslipData['department']) . '</p>
                </div>
                <div>
                    <p><strong>Pay Period:</strong> ' . $payPeriod . '</p>
                    <p><strong>Pay Date:</strong> ' . $paymentDate . '</p>
                    <p><strong>Status:</strong> ' . ucfirst($payslipData['status']) . '</p>
                </div>
            </div>
            
            <div class="payslip-details">
                <div class="payslip-earnings">
                    <h3 style="color: #4361ee; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 15px;">Earnings</h3>
                    <table>
                        <tr>
                            <td>Basic Salary:</td>
                            <td class="text-right">' . formatCurrency($basicSalary) . '</td>
                        </tr>
                        <tr>
                            <td>Allowances:</td>
                            <td class="text-right">' . formatCurrency($allowances) . '</td>
                        </tr>
                        <tr style="font-weight: bold; border-top: 1px solid #ddd;">
                            <td>Total Earnings:</td>
                            <td class="text-right">' . formatCurrency($basicSalary + $allowances) . '</td>
                        </tr>
                    </table>
                </div>
                
                <div class="payslip-deductions">
                    <h3 style="color: #4361ee; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 15px;">Deductions</h3>
                    <table>
                        <tr>
                            <td>Tax:</td>
                            <td class="text-right">' . formatCurrency($deductions * 0.6) . '</td>
                        </tr>
                        <tr>
                            <td>Pension:</td>
                            <td class="text-right">' . formatCurrency($deductions * 0.2) . '</td>
                        </tr>
                        <tr>
                            <td>Other:</td>
                            <td class="text-right">' . formatCurrency($deductions * 0.2) . '</td>
                        </tr>
                        <tr style="font-weight: bold; border-top: 1px solid #ddd;">
                            <td>Total Deductions:</td>
                            <td class="text-right">' . formatCurrency($deductions) . '</td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <div class="payslip-summary">
                <h3 style="margin: 0;">Net Pay: ' . formatCurrency($netSalary) . '</h3>
            </div>
            
            <div class="payslip-footer">
                <p>This is an electronically generated document and does not require a signature.</p>
                <p>If you have any questions about your payslip, please contact HR.</p>
            </div>
        </div>
    </body>
    </html>
    ';
    
    // Configure DomPDF
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Output the generated PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="payslip_' . $payrollId . '.pdf"');
    echo $dompdf->output();
    
} catch(PDOException $e) {
    http_response_code(500);
    exit('Database error: ' . $e->getMessage());
} catch(Exception $e) {
    http_response_code(500);
    exit('PDF generation error: ' . $e->getMessage());
}
?>
