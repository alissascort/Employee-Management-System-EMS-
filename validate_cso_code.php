<?php
ob_clean();
header('Content-Type: application/json');

require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_set_cookie_params(['path'=>'/','httponly'=>true,'samesite'=>'Lax']);
session_start();

error_log('validate_cso_code.php accessed');

try {
    require_once 'db_connect.php';

    $input   = json_decode(file_get_contents('php://input'), true);
    $csoCode = strtoupper(trim($input['cso_code'] ?? ''));

    if (!$csoCode) {
        echo json_encode(['valid'=>false,'error'=>'Missing CSO code']);
        exit;
    }

    // SECURITY CHECK 1: must be a logged-in CSO
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
        echo json_encode(['valid'=>false,'error'=>'You must be logged in as a CSO to validate this code.']);
        exit;
    }

    // SECURITY CHECK 2: format YYYY/CSO/XXXX
    if (!preg_match('/^[0-9]{4}\/CSO\/[0-9]{4}$/', $csoCode)) {
        echo json_encode(['valid'=>false,'error'=>'Invalid format. Expected YYYY/CSO/XXXX (e.g. 2025/CSO/3269)']);
        exit;
    }

    $db  = new Database();
    $pdo = $db->connect();

    // SECURITY CHECK 3: code must belong to the logged-in session user
    $stmt = $pdo->prepare("
    SELECT c.cso_id, c.cso_code, c.full_name, c.status, d.name as department
    FROM csos c
    LEFT JOIN departments d ON c.department_id = d.department_id
    WHERE c.cso_code = ? AND c.status = 'active'
    LIMIT 1
");
    $stmt->execute([$csoCode]);
    $cso = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cso) {
        echo json_encode(['valid'=>false,'error'=>'CSO code not found or account inactive.']);
        exit;
    }

    if ((int)$cso['cso_id'] !== (int)$_SESSION['user_id']) {
        error_log('SECURITY ALERT: session user_id='.$_SESSION['user_id'].
                  ' tried to use cso_code belonging to cso_id='.$cso['cso_id']);
        echo json_encode([
            'valid' => false,
            'error' => 'This CSO code does not belong to your account. Please enter your own code.'
        ]);
        exit;
    }

    // All checks passed — cache in session
    $_SESSION['cso_code'] = $cso['cso_code'];

    echo json_encode([
        'valid'      => true,
        'cso_code'   => $cso['cso_code'],
        'cso_id'     => $cso['cso_id'],
        'name'       => $cso['full_name'],
        'department' => $cso['department']
    ]);

} catch (Exception $e) {
    error_log('CSO code validation error: '.$e->getMessage());
    echo json_encode(['valid'=>false,'error'=>'Server error during validation.']);
}
?>
