<?php
// Ensure clean output
ob_clean();
header('Content-Type: application/json');

require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Start session to access logged-in user data
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

error_log('validate_employee_code.php accessed');

try {
    require_once 'db_connect.php';
    error_log('db_connect.php included successfully');

    $input = json_decode(file_get_contents('php://input'), true);
    $employeeCode = $input['employee_code'] ?? '';
    error_log('Employee code received: ' . $employeeCode);

    if (!$employeeCode) {
        error_log('No employee code provided');
        echo json_encode(['valid' => false, 'error' => 'Missing employee code']);
        exit;
    }

    // ================================================================
    // SECURITY CHECK 1: Must be a logged-in employee
    // ================================================================
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'employee') {
        error_log('Validation attempt by unauthenticated user');
        echo json_encode([
            'valid' => false,
            'error' => 'You must be logged in to validate an employee code.'
        ]);
        exit;
    }

    // ================================================================
    // SECURITY CHECK 2: Validate format
    // Format: YYYY/EMP/XXXX (4 digits year, EMP, 4 digits)
    // ================================================================
    $employeeCodePattern = '/^[0-9]{4}\/EMP\/[0-9]{4}$/';
    if (!preg_match($employeeCodePattern, $employeeCode)) {
        error_log('Invalid employee code format: ' . $employeeCode);
        echo json_encode([
            'valid' => false,
            'error' => 'Invalid employee code format. Expected format: YYYY/EMP/XXXX (e.g., 2025/EMP/0001)'
        ]);
        exit;
    }

    // Connect to database
    error_log('Creating Database instance...');
    $db = new Database();
    error_log('Database instance created');

    error_log('Connecting to database...');
    $pdo = $db->connect();
    if (!$pdo) {
        error_log('Database connection failed');
        echo json_encode(['valid' => false, 'error' => 'Database connection failed']);
        exit;
    }
    error_log('Database connected successfully');

    // ================================================================
    // SECURITY CHECK 3: The submitted code MUST match the logged-in
    // employee's own code. Cross-check against session user_id.
    // This prevents Employee A from submitting Employee B's code.
    // ================================================================
    $sessionUserId = $_SESSION['user_id'];

    error_log('Preparing query for employee code: ' . $employeeCode);
    $stmt = $pdo->prepare("
        SELECT employee_id, employee_code, first_name, last_name, email, department, status 
        FROM employees 
        WHERE employee_code = ? 
          AND status = 'active'
    ");
    error_log('Query prepared successfully');

    error_log('Executing query...');
    $stmt->execute([$employeeCode]);
    error_log('Query executed successfully');

    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    error_log('Query result: ' . ($employee ? 'Employee found' : 'Employee not found'));

    if (!$employee) {
        error_log('Employee code not found or inactive: ' . $employeeCode);
        echo json_encode([
            'valid' => false,
            'error' => 'Employee code not found or inactive.'
        ]);
        exit;
    }

    // ================================================================
    // CRITICAL: Does the found employee match the logged-in user?
    // ================================================================
    if ((int)$employee['employee_id'] !== (int)$sessionUserId) {
        error_log(
            'SECURITY ALERT: Session user_id=' . $sessionUserId .
            ' tried to use employee_code belonging to employee_id=' .
            $employee['employee_id'] .
            ' (' . $employee['first_name'] . ' ' . $employee['last_name'] . ')'
        );
        echo json_encode([
            'valid' => false,
            'error' => 'This employee code does not belong to your account. Please enter your own employee code.'
        ]);
        exit;
    }

    // ================================================================
    // All checks passed — code is valid and belongs to this user
    // ================================================================
    error_log('Employee validation successful for: ' . $employee['first_name'] . ' ' . $employee['last_name']);

    // Cache employee_code in session for other handlers (e.g. attendance_handler)
    $_SESSION['employee_code'] = $employee['employee_code'];

    $response = [
        'valid'         => true,
        'employee_code' => $employeeCode,
        'employee_id'   => $employee['employee_id'],
        'name'          => $employee['first_name'] . ' ' . $employee['last_name'],
        'email'         => $employee['email'],
        'department'    => $employee['department']
    ];
    error_log('Sending success response: ' . json_encode($response));
    echo json_encode($response);
    exit;

} catch (Exception $e) {
    error_log('Employee code validation error: ' . $e->getMessage());
    error_log('Employee code validation error details: ' . $e->getTraceAsString());
    echo json_encode([
        'valid' => false,
        'error' => 'Server error during validation: ' . $e->getMessage()
    ]);
    exit;
}
?>
