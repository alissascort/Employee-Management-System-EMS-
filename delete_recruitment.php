<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['application_id'])) {
        throw new Exception("Application ID is required");
    }
    
    $database = new Database();
    $conn = $database->getConnection();

    // Option 1: Soft delete (recommended) - just change status to 'Deleted'
    $query = "UPDATE recruitment_applications SET status = 'Rejected', notes = CONCAT(COALESCE(notes, ''), '\n\nDELETED: ', NOW()) WHERE id = ?";
    
    // Option 2: Hard delete (permanently remove from database)
     $query = "DELETE FROM recruitment_applications WHERE id = ?";
    
    $stmt = $conn->prepare($query);
    $success = $stmt->execute([$data['application_id']]);
    
    if ($success && $stmt->rowCount() > 0) {
        // Log the deletion activity
        $logQuery = "INSERT INTO recruitment_activity_log 
                    (application_id, action, details, created_at)
                    VALUES (?, 'APPLICATION_DELETED', 'Recruitment application was deleted', NOW())";
        $logStmt = $conn->prepare($logQuery);
        $logStmt->execute([$data['application_id']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Recruitment application deleted successfully'
        ]);
    } else {
        throw new Exception("Application not found or already deleted");
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting recruitment application: ' . $e->getMessage()
    ]);
}
?>