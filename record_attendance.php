<?php
header("Content-Type: application/json");
require_once 'db_connect.php';

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate input
$requiredFields = ['employee_code', 'action']; // action: 'check_in' or 'check_out'
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

$employeeCode = $input['employee_code'];
$action = $input['action'];
$currentDate = date('Y-m-d');
$currentTime = date('H:i:s');

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Get employee ID from code
    $employeeStmt = $conn->prepare("SELECT employee_id FROM employees WHERE employee_code = :code");
    $employeeStmt->bindParam(':code', $employeeCode);
    $employeeStmt->execute();
    
    if ($employeeStmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit;
    }
    
    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);
    $employeeId = $employee['employee_id'];
    
    // Check if attendance record exists for today
    $attendanceStmt = $conn->prepare("SELECT * FROM attendance 
                                    WHERE employee_id = :id AND date = :date");
    $attendanceStmt->bindParam(':id', $employeeId);
    $attendanceStmt->bindParam(':date', $currentDate);
    $attendanceStmt->execute();
    $attendance = $attendanceStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($action === 'check_in') {
        if ($attendance) {
            if ($attendance['check_in']) {
                echo json_encode(['success' => false, 'message' => 'Employee already checked in today']);
                exit;
            }
            
            // Update existing record with check-in
            $updateStmt = $conn->prepare("UPDATE attendance 
                                        SET check_in = :time, 
                                            status = :status 
                                        WHERE attendance_id = :id");
            $updateStmt->bindParam(':time', $currentTime);
            
            // Determine if late (after 8:30 AM)
            $status = (strtotime($currentTime) > strtotime('08:30:00')) ? 'late' : 'present';
            $updateStmt->bindParam(':status', $status);
            $updateStmt->bindParam(':id', $attendance['attendance_id']);
            
            if ($updateStmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Check-in recorded successfully',
                    'status' => $status
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to record check-in']);
            }
        } else {
            // Create new attendance record with check-in
            $insertStmt = $conn->prepare("INSERT INTO attendance 
                                         (employee_id, date, check_in, status) 
                                         VALUES (:id, :date, :time, :status)");
            $insertStmt->bindParam(':id', $employeeId);
            $insertStmt->bindParam(':date', $currentDate);
            $insertStmt->bindParam(':time', $currentTime);
            
            $status = (strtotime($currentTime) > strtotime('08:30:00')) ? 'late' : 'present';
            $insertStmt->bindParam(':status', $status);
            
            if ($insertStmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Check-in recorded successfully',
                    'status' => $status
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to record check-in']);
            }
        }
    } elseif ($action === 'check_out') {
        if (!$attendance) {
            echo json_encode(['success' => false, 'message' => 'Employee has not checked in today']);
            exit;
        }
        
        if ($attendance['check_out']) {
            echo json_encode(['success' => false, 'message' => 'Employee already checked out today']);
            exit;
        }
        
        // Update record with check-out
        $updateStmt = $conn->prepare("UPDATE attendance 
                                    SET check_out = :time 
                                    WHERE attendance_id = :id");
        $updateStmt->bindParam(':time', $currentTime);
        $updateStmt->bindParam(':id', $attendance['attendance_id']);
        
        if ($updateStmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Check-out recorded successfully'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to record check-out']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>