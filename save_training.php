<?php
session_start();
header("Content-Type: application/json");
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'hr') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}


require_once 'db_connect.php';

$response = ['success' => false, 'message' => 'An error occurred'];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $db = new Database();
    $conn = $db->connect();
    
    $stmt = $conn->prepare("
        INSERT INTO trainings 
        (title, type, description, start_date, end_date, duration, max_participants, format,
         location, virtual_details, trainer_name, trainer_email, trainer_bio, target_audience,
         target_department, prerequisites, materials_needed, objectives, cost_per_participant,
         total_budget, budget_code, status, certificate_available, mandatory, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $input['title'],
        $input['type'],
        $input['description'],
        $input['start_date'],
        $input['end_date'],
        $input['duration'],
        $input['max_participants'],
        $input['format'],
        $input['location'],
        $input['virtual_details'],
        $input['trainer_name'],
        $input['trainer_email'],
        $input['trainer_bio'],
        $input['target_audience'],
        $input['target_department'],
        $input['prerequisites'],
        $input['materials_needed'],
        $input['objectives'],
        $input['cost_per_participant'],
        $input['total_budget'],
        $input['budget_code'],
        $input['status'],
        $input['certificate_available'],
        $input['mandatory'],
        $_SESSION['user_id']
    ]);
    
    $trainingId = $conn->lastInsertId();
    
    // Save participants if individual employees are selected
    if ($input['target_audience'] === 'Individual Employees' && !empty($input['participants'])) {
        foreach ($input['participants'] as $participant) {
            $participantStmt = $conn->prepare("
                INSERT INTO training_participants (training_id, employee_id, status)
                VALUES (?, ?, 'Registered')
            ");
            $participantStmt->execute([$trainingId, $participant['employee_id']]);
        }
    }
    
    $response = [
        'success' => true,
        'message' => 'Training saved successfully',
        'training_id' => $trainingId
    ];
    
} catch (PDOException $e) {
    error_log("Database Error in save_training.php: " . $e->getMessage());
    $response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Error in save_training.php: " . $e->getMessage());
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
?>