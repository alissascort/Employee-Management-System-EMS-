?php
header('Content-Type: application/json');

require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();
// Database connection
$db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');

// Get vulnerability details
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = $_GET['id'];
    $stmt = $db-prepare("SELECT * FROM vulnerabilities WHERE id = ?");
    $stmt->execute([$id]);
    $vulnerability = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'vulnerability' => $vulnerability]);
}

// Mark as fixed
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $stmt = $db->prepare("UPDATE vulnerabilities SET status = 'FIXED', fixed_at = NOW() WHERE id = ?");
    $success = $stmt->execute([$data['vulnerability_id']]);
    
    echo json_encode(['success' => $success]);
}
?>
