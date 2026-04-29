<?php
header("Content-Type: application/json");
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

// Configure session settings BEFORE session_start()
// SECURE SESSION CONFIG (Development - HTTP)
ini_set('session.cookie_httponly', 1);       // Protect against XSS
ini_set('session.use_strict_mode', 1);       // Prevent session fixation
ini_set('session.cookie_secure', 0);         // Disable HTTPS-only (since localhost uses HTTP)
ini_set('session.cookie_samesite', 'Lax');   // Balance security & usability

session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

// Debug session status
error_log('Session status: ' . session_status());
error_log('Session user: ' . ($_SESSION['user_id'] ?? 'NOT SET'));


if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['employee_id']) || !isset($input['start_date']) || !isset($input['end_date'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$employeeCode = $input['employee_id']; // Frontend sends employee_id but we use employee_code in database
$startDate = $input['start_date'];
$endDate = $input['end_date'];

// Validate dates
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
    
    // Check if user is authorized to view this employee's attendance
    if ($_SESSION['user_type'] === 'employee' && $_SESSION['user_id'] != $employeeCode) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized to view this data']);
        exit;
    }
    
    // Get attendance records
    $stmt = $conn->prepare("SELECT * FROM attendance 
                          WHERE employee_code = :employee_code 
                          AND date BETWEEN :start AND :end
                          ORDER BY date DESC");
    $stmt->bindParam(':employee_code', $employeeCode);
    $stmt->bindParam(':start', $startDate);
    $stmt->bindParam(':end', $endDate);
    $stmt->execute();
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total hours for each record
    foreach ($attendance as &$record) {
        if ($record['check_in_time'] && $record['check_out_time']) {
            $checkIn = strtotime($record['check_in_time']);
            $checkOut = strtotime($record['check_out_time']);
            $totalSeconds = $checkOut - $checkIn;
            $record['total_hours'] = round($totalSeconds / 3600, 2); // Convert to hours
        } else {
            $record['total_hours'] = null;
        }
        
        $record['check_in'] = $record['check_in_time'];
        $record['check_out'] = $record['check_out_time'];
    }
    
    echo json_encode([
        'success' => true,
        'attendance' => $attendance
    ]);
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
