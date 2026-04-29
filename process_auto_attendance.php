<?php
/**
 * Automated Attendance Processor
 * This script marks employees as absent if they haven't checked in by 12:00 PM
 * Can be run manually or via cron job
 */

require_once 'db_connect.php';

// Set timezone
date_default_timezone_set('Africa/Lagos');

// Log function
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    error_log($logMessage, 3, 'auto_attendance.log');
    echo $logMessage;
}

try {
    $database = new Database();
    $pdo = $database->connect();
    
    if (!$pdo) {
        throw new Exception("Database connection failed");
    }
    
    logMessage("Starting auto-attendance processing...");
    
    // Get current time
    $currentTime = date('H:i:s');
    $currentDate = date('Y-m-d');
    
    // Only process if it's after 12:00 PM
    if (strtotime($currentTime) < strtotime('12:00:00')) {
        logMessage("Current time ($currentTime) is before 12:00 PM. Auto-absent processing not needed.");
        exit;
    }
    
    logMessage("Current time: $currentTime - Processing auto-absent for employees who haven't checked in");
    
    // Get all active employees who haven't checked in today
    $stmt = $pdo->prepare("
        SELECT e.employee_code, e.first_name, e.last_name 
        FROM employees e 
        WHERE e.status = 'active' 
        AND e.employee_code NOT IN (
            SELECT DISTINCT employee_code 
            FROM attendance 
            WHERE date = CURDATE()
        )
    ");
    $stmt->execute();
    $employeesWithoutAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    logMessage("Found " . count($employeesWithoutAttendance) . " employees without attendance records");
    
    $processedCount = 0;
    $errorCount = 0;
    
    foreach ($employeesWithoutAttendance as $employee) {
        try {
            // Check if auto-absent record already exists for today
            $checkStmt = $pdo->prepare("
                SELECT id FROM attendance 
                WHERE employee_code = ? AND date = CURDATE() AND reason LIKE 'Auto-absent%'
            ");
            $checkStmt->execute([$employee['employee_code']]);
            
            if ($checkStmt->fetch()) {
                logMessage("Auto-absent record already exists for {$employee['first_name']} {$employee['last_name']} ({$employee['employee_code']}) - skipping");
                continue;
            }
            
            // Insert auto-absent record
            $insertStmt = $pdo->prepare("
                INSERT INTO attendance 
                (employee_code, date, status, check_in_time, reason) 
                VALUES (?, CURDATE(), 'absent', NOW(), 'Auto-absent: After 12:00 PM without check-in')
            ");
            
            $result = $insertStmt->execute([
                $employee['employee_code']
            ]);
            
            if ($result) {
                logMessage("Auto-absent recorded for {$employee['first_name']} {$employee['last_name']} ({$employee['employee_code']})");
                $processedCount++;
            } else {
                logMessage("ERROR: Failed to record auto-absent for {$employee['employee_code']}");
                $errorCount++;
            }
            
        } catch (Exception $e) {
            logMessage("ERROR: Exception while processing {$employee['employee_code']}: " . $e->getMessage());
            $errorCount++;
        }
    }
    
    logMessage("Auto-attendance processing completed:");
    logMessage("- Processed: $processedCount employees");
    logMessage("- Errors: $errorCount employees");
    
    // Get summary for today's attendance
    $summaryStmt = $pdo->prepare("
        SELECT 
            status,
            COUNT(*) as count
        FROM attendance 
        WHERE date = CURDATE()
        GROUP BY status
    ");
    $summaryStmt->execute();
    $summary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);
    
    logMessage("Today's attendance summary:");
    foreach ($summary as $stat) {
        logMessage("- {$stat['status']}: {$stat['count']} employees");
    }
    
} catch (Exception $e) {
    logMessage("CRITICAL ERROR: " . $e->getMessage());
    exit(1);
}

logMessage("Auto-attendance processing finished successfully.");
?> 