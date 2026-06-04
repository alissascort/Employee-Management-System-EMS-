<?php
header('Content-Type: application/json');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Database connection
try {
    $db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$employeeCode = $_SESSION['employee_code'] ?? null;

if (!$employeeCode) {
    echo json_encode(['success' => false, 'message' => 'Employee code not found']);
    exit;
}

try {
    $stmt = $db->prepare("SELECT created_at, last_password_change FROM staff_profiles WHERE employee_code = ?");
    $stmt->execute([$employeeCode]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee || !$employee['created_at']) {
        echo json_encode(['success' => true, 'expires_soon' => false, 'expired' => false]);
        exit;
    }
    
    $expiryDate = new DateTime($employee['created_at']);
    $today = new DateTime();
    $interval = $today->diff($expiryDate);
    $daysRemaining = $interval->days;
    
    $expiresSoon = $daysRemaining <= 7 && $daysRemaining > 0; // Warn if 7 days or less
    $expired = $expiryDate < $today;
    
    echo json_encode([
        'success' => true,
        'expires_soon' => $expiresSoon,
        'expired' => $expired,
        'days_remaining' => $daysRemaining,
        'expiry_date' => $employee['created_at'],
        'last_change' => $employee['last_password_change']
    ]);
    
} catch (PDOException $e) {
    error_log("Password expiry check error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error']);
}
?>
