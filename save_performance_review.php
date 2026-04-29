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
        INSERT INTO performance_reviews 
        (employee_id, review_period, review_date, reviewer, overall_rating, overall_score, 
         metrics, strengths, improvement_areas, goals, comments, status, next_review_date, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $input['employee_id'],
        $input['review_period'],
        $input['review_date'],
        $input['reviewer'],
        $input['overall_rating'],
        $input['overall_score'],
        json_encode($input['metrics']),
        $input['strengths'],
        $input['improvement_areas'],
        $input['goals'],
        $input['comments'],
        $input['status'],
        $input['next_review_date'],
        $_SESSION['user_id']
    ]);
    
    $response = [
        'success' => true,
        'message' => 'Performance review saved successfully',
        'review_id' => $conn->lastInsertId()
    ];
    
} catch (PDOException $e) {
    error_log("Database Error in save_performance_review.php: " . $e->getMessage());
    $response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Error in save_performance_review.php: " . $e->getMessage());
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
?>