<?php
header('Content-Type: application/json');

// Database connection
try {
    $db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'has_questions' => false]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$employeeCode = $data['employee_code'] ?? '';

if (empty($employeeCode)) {
    echo json_encode(['success' => false, 'has_questions' => false]);
    exit;
}

try {
    $stmt = $db->prepare("SELECT security_questions_set FROM staff_profiles WHERE employee_code = ?");
    $stmt->execute([$employeeCode]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['security_questions_set']) {
        echo json_encode(['success' => true, 'has_questions' => true]);
    } else {
        echo json_encode(['success' => true, 'has_questions' => false]);
    }
} catch (PDOException $e) {
    error_log("Check security questions error: " . $e->getMessage());
    echo json_encode(['success' => false, 'has_questions' => false]);
}
?>
