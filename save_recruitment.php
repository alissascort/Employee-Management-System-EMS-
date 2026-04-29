<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['position_title', 'applicant_name', 'email'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Validate email
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid email format");
    }
    
    $database = new Database();
    $conn = $database->getConnection();

    // Use NULL for external applicants since they don't have employee IDs
    $query = "INSERT INTO recruitment_applications 
              (applicant_id, position, applicant_name, email, phone, application_date, 
               status, cover_letter, interview_date, interview_notes, assigned_to, 
               priority, employment_type, notes)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    $success = $stmt->execute([
        NULL, // applicant_id - NULL for external applicants
        $data['position_title'],
        $data['applicant_name'],
        $data['email'],
        $data['phone'] ?? NULL,
        $data['application_date'] ?? date('Y-m-d'),
        $data['status'] ?? 'Pending',
        $data['cover_letter'] ?? NULL,
        !empty($data['interview_date']) ? $data['interview_date'] : NULL,
        $data['interview_notes'] ?? NULL,
        !empty($data['assigned_to']) ? $data['assigned_to'] : NULL,
        $data['priority'] ?? 'Medium',
        $data['employment_type'] ?? 'Full-Time',
        buildInternalNotes($data)
    ]);
    
    if ($success) {
        // Log the activity
        $applicationId = $conn->lastInsertId();
        
        // Insert into recruitment_activity_log
        $logQuery = "INSERT INTO recruitment_activity_log 
                    (application_id, action, details, created_at)
                    VALUES (?, 'APPLICATION_CREATED', 'New recruitment application submitted', NOW())";
        $logStmt = $conn->prepare($logQuery);
        $logStmt->execute([$applicationId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Recruitment application saved successfully',
            'application_id' => $applicationId
        ]);
    } else {
        throw new Exception("Failed to save recruitment application");
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error saving recruitment application: ' . $e->getMessage()
    ]);
}

function buildInternalNotes($data) {
    $notes = [];
    
    if (!empty($data['job_description'])) {
        $notes[] = "JOB DESCRIPTION:\n" . $data['job_description'];
    }
    if (!empty($data['job_requirements'])) {
        $notes[] = "REQUIREMENTS:\n" . $data['job_requirements'];
    }
    if (!empty($data['internal_notes'])) {
        $notes[] = "INTERNAL NOTES:\n" . $data['internal_notes'];
    }
    if (!empty($data['salary_min']) || !empty($data['salary_max'])) {
        $salary = "SALARY RANGE: ";
        if (!empty($data['salary_min'])) $salary .= "$" . number_format($data['salary_min'], 2);
        if (!empty($data['salary_min']) && !empty($data['salary_max'])) $salary .= " - ";
        if (!empty($data['salary_max'])) $salary .= "$" . number_format($data['salary_max'], 2);
        $notes[] = $salary;
    }
    
    return implode("\n\n", $notes);
}
?>