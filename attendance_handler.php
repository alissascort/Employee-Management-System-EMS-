<?php
/**
 * Enhanced Attendance Handler
 * Improved with geolocation tracking, better validation, and comprehensive logging
 */
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();
require_once 'db_connect.php';

// Enhanced database connection
$database = Database::getInstance();
$pdo = $database->getConnection();

if (!$pdo) {
    error_log("Database connection failed in attendance_handler.php");
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

header('Content-Type: application/json');

// Security and validation settings
$businessHours = [
    'start' => '07:45:00',
    'end' => '17:00:00',
    'lunch_start' => '12:00:00',
    'lunch_end' => '13:00:00',
    'checkout_start' => '16:15:00',
    'checkout_end' => '19:00:00',
    'auto_checkout_deadline' => '19:00:00'
];

// ============================================================
// OFFICE COORDINATES — Fortishield-Matrix office
// Previous attempt: -6.8300077, 39.1958610 (incorrect — laptop WiFi triangulation)
// Corrected:        -6.8222976, 39.2462336 (verified via browser GPS on-site)
// Last updated: 2026-03-23
// ============================================================
define('OFFICE_LAT', -6.8222976);
define('OFFICE_LNG', 39.2462336);
define('MAX_DISTANCE_METERS', 100); // 100 meters radius

$input = json_decode(file_get_contents('php://input'), true);
$employeeCode = $input['employee_code'] ?? ($_SESSION['employee_code'] ?? null);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Don't exit early for get_time_book action as it can use GET parameter
$action = $_GET['action'] ?? ($input['action'] ?? '');
if (!$employeeCode && $action !== 'get_time_book') {
    echo json_encode(['success' => false, 'message' => 'Employee code is required']);
    exit;
}

// Add these helper functions at the top after other functions
function isWorkingDay() {
    $dayOfWeek = date('w'); // 0 = Sunday, 6 = Saturday
    return $dayOfWeek >= 1 && $dayOfWeek <= 5; // Monday to Friday
}

function isSaturday() {
    return date('w') === 6;
}

function isSunday() {
    return date('w') === 0;
}

function isAfterWorkHours() {
    $currentTime = date('H:i:s');
    return $currentTime >= '16:15:00';
}

function isEligibleForOvertime($employeeCode, $pdo) {
    // Check if employee has overtime in contract
    $stmt = $pdo->prepare("
        SELECT overtime_eligible 
        FROM employee_contracts 
        WHERE employee_code = ? AND status = 'active'
    ");
    $stmt->execute([$employeeCode]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $contract && $contract['overtime_eligible'] == 1;
}

function calculateOvertimeHours($checkInTime, $checkOutTime, $overtimeStart = '16:15:00') {
    $checkIn = new DateTime($checkInTime);
    $checkOut = new DateTime($checkOutTime);
    $overtimeStartTime = new DateTime($checkIn->format('Y-m-d') . ' ' . $overtimeStart);
    
    // If checkout is before overtime start, no overtime
    if ($checkOut <= $overtimeStartTime) {
        return 0;
    }
    
    // Calculate overtime from whichever is later: overtime start or actual checkin
    $overtimeFrom = $checkIn > $overtimeStartTime ? $checkIn : $overtimeStartTime;
    
    $interval = $overtimeFrom->diff($checkOut);
    $overtimeHours = $interval->h + ($interval->i / 60);
    
    return round(max(0, $overtimeHours), 2);
}

function validateOvertimeWork($overtimeData) {
    // In production, this would integrate with project management system
    // For now, we'll simulate validation based on justification quality
    
    $justification = $overtimeData['justification'] ?? '';
    $project = $overtimeData['project'] ?? '';
    $reason = $overtimeData['reason'] ?? '';
    
    // Basic validation rules
    $minJustificationLength = 30;
    $urgentKeywords = ['urgent', 'emergency', 'deadline', 'client', 'critical', 'production', 'fix', 'issue'];
    
    $hasUrgentContext = false;
    foreach ($urgentKeywords as $keyword) {
        if (stripos($justification, $keyword) !== false || 
            stripos($project, $keyword) !== false ||
            stripos($reason, $keyword) !== false) {
            $hasUrgentContext = true;
            break;
        }
    }
    
    if (strlen($justification) < $minJustificationLength) {
        return [
            'approved' => false,
            'reason' => 'Justification too short',
            'type' => 'rejected'
        ];
    }
    
    if (!$hasUrgentContext) {
        return [
            'approved' => false,
            'reason' => 'No urgent context detected',
            'type' => 'pending_supervisor'
        ];
    }
    
    return [
        'approved' => true,
        'reason' => 'Valid overtime work',
        'type' => 'approved'
    ];
}


// Helper functions
function validateLocation($latitude, $longitude) {
    // Validate coordinate ranges
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        throw new Exception('Invalid coordinates provided');
    }
    
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        throw new Exception('Coordinates out of valid range');
    }

    // NOTE: The local variables below ($officeLat, $officeLng, $maxDistance)
    // are intentionally left unused — the Haversine formula uses the
    // OFFICE_LAT / OFFICE_LNG / MAX_DISTANCE_METERS constants defined above.
    // Update only the constants at the top of this file when the office moves.
    $officeLat = OFFICE_LAT;   // kept for reference clarity
    $officeLng = OFFICE_LNG;   // kept for reference clarity
    $maxDistance = MAX_DISTANCE_METERS; // kept for reference clarity

    // Haversine formula for accurate distance calculation
    $earthRadius = 6371000; // in meters
    
    $latFrom = deg2rad(OFFICE_LAT);
    $lonFrom = deg2rad(OFFICE_LNG);
    $latTo   = deg2rad($latitude);
    $lonTo   = deg2rad($longitude);
    
    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;
    
    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + 
        cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
    
    $distance = $angle * $earthRadius;
    
    error_log("Distance calculation: $distance meters from office for employee");
    
    // Return true if within allowed distance (100 meters)
    return $distance <= MAX_DISTANCE_METERS;
}

function logAttendanceEvent($employeeCode, $event, $data, $pdo) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO attendance_logs (employee_code, event_type, event_data, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$employeeCode, $event, json_encode($data)]);
    } catch (Exception $e) {
        error_log("Failed to log attendance event: " . $e->getMessage());
    }
}

function calculateWorkHours($checkInTime, $checkOutTime) {
    global $businessHours;
    $checkIn = new DateTime($checkInTime);
    $checkOut = new DateTime($checkOutTime);
    $interval = $checkIn->diff($checkOut);
    $totalHours = $interval->h + ($interval->i / 60);
    // subtract lunch if between
    $lunchStart = strtotime($businessHours['lunch_start']);
    $lunchEnd = strtotime($businessHours['lunch_end']);
    if (strtotime($checkInTime) < $lunchStart && strtotime($checkOutTime) > $lunchEnd) {
        $totalHours -= 1;
    }
    return round(max(0,$totalHours), 2);
}

/* ------------------------------------------------------
   Intelligent Auto-Checkout Helpers
------------------------------------------------------ */

function getUserCheckoutPattern($employeeCode, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM employee_checkout_patterns WHERE employee_code = ?");
    $stmt->execute([$employeeCode]);
    $pattern = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pattern) return initializeDefaultPattern($employeeCode, $pdo);
    return $pattern;
}

function initializeDefaultPattern($employeeCode, $pdo) {
    try {
        // Resolve employee_id from employee_code
        $stmt = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_code = ?");
        $stmt->execute([$employeeCode]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) {
            throw new Exception("Employee not found for code: $employeeCode");
        }
        $employeeId = $employee['employee_id'];

        // Calculate average checkout time from attendance
        $stmt = $pdo->prepare("
            SELECT AVG(TIME_TO_SEC(check_out_time)) as avg_seconds 
            FROM attendance 
            WHERE employee_code = ? AND check_out_time IS NOT NULL
              AND check_out_time >= '16:15:00' AND check_out_time <= '19:00:00'
        ");
        $stmt->execute([$employeeCode]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $defaultTime = '17:30:00';
        if ($result && $result['avg_seconds'] > 0) {
            $defaultTime = date('H:i:s', $result['avg_seconds']);
        }

        // Insert default pattern with employee_id
        $stmt = $pdo->prepare("
            INSERT INTO employee_checkout_patterns 
            (employee_id, typical_checkout_time, flexibility_minutes, consistency_score, adaptive_threshold, miss_count)
            VALUES (?, ?, 30, 1.00, 3, 0)
        ");
        $stmt->execute([$employeeId, $defaultTime]);

        return [
            'typical_checkout_time' => $defaultTime,
            'flexibility_minutes' => 30,
            'consistency_score' => 1.00,
            'adaptive_threshold' => 3,
            'miss_count' => 0
        ];
    } catch (Exception $e) {
        error_log("Error initializing pattern for $employeeCode: " . $e->getMessage());
        return [
            'typical_checkout_time' => '17:30:00',
            'flexibility_minutes' => 30,
            'consistency_score' => 1.00,
            'adaptive_threshold' => 3,
            'miss_count' => 0
        ];
    }
}


function updateUserPattern($employeeCode, $actualCheckoutTime, $pdo) {
    $pattern = getUserCheckoutPattern($employeeCode, $pdo);
    $typicalSeconds = strtotime($pattern['typical_checkout_time']);
    $actualSeconds = strtotime($actualCheckoutTime);
    $newSeconds = ($typicalSeconds * 0.7) + ($actualSeconds * 0.3);
    $newTypicalTime = date('H:i:s', $newSeconds);
    $timeDiff = abs($typicalSeconds - $actualSeconds) / 60;
    $flexibility = $pattern['flexibility_minutes'];
    $consistencyChange = ($timeDiff <= $flexibility) ? 0.05 : -0.1;
    $newConsistency = max(0.1, min(1.0, $pattern['consistency_score'] + $consistencyChange));
    $stmt = $pdo->prepare("UPDATE employee_checkout_patterns 
        SET typical_checkout_time = ?, consistency_score = ?, miss_count = 0,
            last_manual_checkout = CURDATE(), updated_at = NOW()
        WHERE employee_code = ?");
    $stmt->execute([$newTypicalTime, $newConsistency, $employeeCode]);
}

function scheduleAutoCheckout($employeeCode, $pdo) {
    $pattern = getUserCheckoutPattern($employeeCode, $pdo);
    $checkoutTime = ($pattern['consistency_score'] > 0.5) ? $pattern['typical_checkout_time'] : '18:00:00';
    $stmt = $pdo->prepare("INSERT INTO auto_checkout_logs 
        (employee_code, checkout_date, scheduled_time, actual_time, reason, pattern_used)
        VALUES (?, CURDATE(), ?, NULL, 'pattern_based', ?)");
    $stmt->execute([$employeeCode, $checkoutTime, $pattern['consistency_score'] > 0.5]);
    return $checkoutTime;
}

function processAutoCheckout($employeeCode, $pdo) {
    $stmt = $pdo->prepare("SELECT id, scheduled_time FROM auto_checkout_logs 
        WHERE employee_code = ? AND checkout_date = CURDATE() AND actual_time IS NULL");
    $stmt->execute([$employeeCode]);
    $scheduled = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($scheduled) {
        $currentTime = date('H:i:s');
        $stmt = $pdo->prepare("UPDATE attendance 
            SET check_out_time = CONCAT(CURDATE(), ' ', ?),
                work_hours = TIMESTAMPDIFF(MINUTE, check_in_time, CONCAT(CURDATE(),' ',?))/60
            WHERE employee_code = ? AND date = CURDATE() AND check_out_time IS NULL");
        $stmt->execute([$currentTime, $currentTime, $employeeCode]);
        $stmt = $pdo->prepare("UPDATE auto_checkout_logs SET actual_time = ? WHERE id = ?");
        $stmt->execute([$currentTime, $scheduled['id']]);
        return true;
    }
    return false;
}

function timeToMinutes($time) {
    $parts = explode(':', $time);
    return $parts[0] * 60 + $parts[1];
}

try {
    switch ($action) {
        case 'get_today_status':
            $stmt = $pdo->prepare("SELECT * FROM attendance 
                                  WHERE employee_code = ? AND date = CURDATE()");
            $stmt->execute([$employeeCode]);
            $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($attendance) {
                // Check if the check-in time is within valid business hours
                $checkInTime = strtotime($attendance['check_in_time']);
                $checkInHour = date('H', $checkInTime);
                $checkInMinute = date('i', $checkInTime);
                $checkInTotalMinutes = $checkInHour * 60 + $checkInMinute;
                
                // Valid business hours: 7:45 AM to 12:00 PM
                $validStart = 7 * 60 + 45; // 7:45 AM
                $validEnd = 12 * 60; // 12:00 PM
                
                $isValidCheckIn = ($checkInTotalMinutes >= $validStart && $checkInTotalMinutes <= $validEnd);
                
                echo json_encode([
                    'success' => true,
                    'checkedIn' => true,
                    'checkedOut' => !is_null($attendance['check_out_time']),
                    'status' => $attendance['status'],
                    'check_in_time' => date('h:i A', $checkInTime),
                    'check_out_time' => $attendance['check_out_time'] ? date('h:i A', strtotime($attendance['check_out_time'])) : null,
                    'isValidCheckIn' => $isValidCheckIn,
                    'checkInTotalMinutes' => $checkInTotalMinutes,
                    'validStart' => $validStart,
                    'validEnd' => $validEnd
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'checkedIn' => false
                ]);
            }
            break;
            
        case 'check_in':
            $status = $input['status'] ?? '';
            $reason = $input['reason'] ?? '';
            $latitude = $input['latitude'] ?? null;
            $longitude = $input['longitude'] ?? null;
            $deviceInfo = $input['device'] ?? null;
            $isSaturday = $input['is_saturday'] ?? false;
            
            // Weekend validation
            if (isSunday()) {
                throw new Exception('Check-ins are not allowed on Sundays.');
            }
            
            if (isSaturday() && !$isSaturday) {
                throw new Exception('Saturday check-ins require special overtime authorization. Please contact your supervisor.');
            }
            
            if (!in_array($status, ['present', 'present_late', 'late', 'absent'])) {
                throw new Exception('Invalid attendance status');
            }
            
            // MANDATORY LOCATION VALIDATION
            if (!$latitude || !$longitude) {
                throw new Exception('Location data is required for attendance check-in. Please enable location services.');
            }
            
            // Validate geolocation - now mandatory
            if (!validateLocation($latitude, $longitude)) {
                error_log("Location validation failed for employee $employeeCode at coordinates ($latitude, $longitude)");
                echo json_encode([
                    'success' => false,
                    'message' => 'You are too far from the office location. Please be within 100 meters of FortishieldMatrix office to check in.'
                ]);
                exit;
            }
            
            // Check overtime eligibility for Saturday
            if (isSaturday()) {
                if (!isEligibleForOvertime($employeeCode, $pdo)) {
                    throw new Exception('You are not eligible for Saturday overtime work. Please check your contract.');
                }
                $status = 'overtime'; // Force overtime status for Saturday
            }
            
            // Log successful location validation
            error_log("Location validation passed for employee $employeeCode");
            
            // Check if already checked in today
            $stmt = $pdo->prepare("SELECT id, check_in_time FROM attendance 
                                  WHERE employee_code = ? AND date = CURDATE()");
            $stmt->execute([$employeeCode]);
            $existingAttendance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingAttendance) {
                // Check if the existing check-in was within valid business hours
                $checkInTime = strtotime($existingAttendance['check_in_time']);
                $checkInHour = date('H', $checkInTime);
                $checkInMinute = date('i', $checkInTime);
                $checkInTotalMinutes = $checkInHour * 60 + $checkInMinute;
                
                // Valid business hours: 7:45 AM to 12:00 PM
                $validStart = 7 * 60 + 45; // 7:45 AM
                $validEnd = 12 * 60; // 12:00 PM
                
                $isValidCheckIn = ($checkInTotalMinutes >= $validStart && $checkInTotalMinutes <= $validEnd);
                
                if ($isValidCheckIn) {
                    throw new Exception('You have already checked in today during valid business hours');
                } else {
                    // Invalid check-in time - allow re-check-in by updating the existing record
                    error_log("Allowing re-check-in for employee $employeeCode - previous check-in at " . date('H:i', $checkInTime) . " was outside business hours");
                    
                    $stmt = $pdo->prepare("UPDATE attendance 
                                          SET status = ?, check_in_time = NOW(), reason = ?, 
                                              location_data = ?, device_info = ?, updated_at = NOW()
                                          WHERE employee_code = ? AND date = CURDATE()");
                    $stmt->execute([
                        $status, 
                        $reason, 
                        json_encode([
                            'latitude' => $latitude, 
                            'longitude' => $longitude,
                            'office_lat' => OFFICE_LAT,
                            'office_lng' => OFFICE_LNG,
                            'validation_passed' => true
                        ]),
                        json_encode($deviceInfo),
                        $employeeCode
                    ]);
                    
                    // Log attendance event
                    logAttendanceEvent($employeeCode, 'check_in', [
                        'status' => $status,
                        'reason' => $reason,
                        'location' => ['latitude' => $latitude, 'longitude' => $longitude],
                        'device' => $deviceInfo,
                        'recheck_in' => true,
                        'location_validation' => 'passed'
                    ], $pdo);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Attendance re-recorded (previous check-in was outside business hours)',
                        'check_in_time' => date('h:i A'),
                        'recheck_in' => true
                    ]);
                    break;
                }
            }
            
            // Insert new attendance record
            $stmt = $pdo->prepare("INSERT INTO attendance 
                                  (employee_code, date, status, check_in_time, reason, location_data, device_info, created_at) 
                                  VALUES (?, CURDATE(), ?, NOW(), ?, ?, ?, NOW())");
            $stmt->execute([
                $employeeCode, 
                $status, 
                $reason,
                json_encode([
                    'latitude' => $latitude, 
                    'longitude' => $longitude,
                    'office_lat' => OFFICE_LAT,
                    'office_lng' => OFFICE_LNG,
                    'validation_passed' => true
                ]),
                json_encode($deviceInfo)
            ]);
            
            // Log attendance event
            logAttendanceEvent($employeeCode, 'check_in', [
                'status' => $status,
                'reason' => $reason,
                'location' => ['latitude' => $latitude, 'longitude' => $longitude],
                'device' => $deviceInfo,
                'location_validation' => 'passed'
            ], $pdo);
            
            echo json_encode([
                'success' => true,
                'message' => 'Attendance recorded successfully',
                'check_in_time' => date('h:i A'),
                'is_overtime' => isSaturday() || $status === 'overtime'
            ]);
            break;
            
        case 'check_out':
            $latitude = $input['latitude'] ?? null;
            $longitude = $input['longitude'] ?? null;
            $deviceInfo = $input['device'] ?? null;
            $isOvertime = $input['is_overtime'] ?? false;
            $overtimeData = $input['overtime_data'] ?? null;
            
            // Check if already checked out today
            $stmt = $pdo->prepare("SELECT id, check_out_time, check_in_time FROM attendance 
                                  WHERE employee_code = ? AND date = CURDATE()");
            $stmt->execute([$employeeCode]);
            $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$attendance) {
                throw new Exception('You need to check in first');
            }
            
            if ($attendance['check_out_time']) {
                throw new Exception('You have already checked out today');
            }
            
            $currentTime = date('H:i:s');
            $checkoutDeadline = '16:15:00';
            
            // If after 16:15 and not marked as overtime
            if (strtotime($currentTime) > strtotime($checkoutDeadline) && !$isOvertime) {
                throw new Exception('Overtime declaration required after 16:15. Please declare overtime work.');
            }

            if (strtotime($currentTime) > strtotime($GLOBALS['businessHours']['checkout_end'])) {
                throw new Exception('Manual checkout closed, system will auto-checkout');
            }
            
            // Validate overtime work if declared
            $overtimeHours = 0;
            $overtimeValidation = null;
    
            if ($isOvertime && $overtimeData) {
                if (!isEligibleForOvertime($employeeCode, $pdo)) {
                    throw new Exception('You are not eligible for overtime work. Please check your contract.');
                }
                
                $overtimeValidation = validateOvertimeWork($overtimeData);
                
                if (!$overtimeValidation['approved']) {
                    throw new Exception('Overtime work validation failed: ' . $overtimeValidation['reason']);
                }
                
                // Calculate overtime hours
                $overtimeHours = calculateOvertimeHours($attendance['check_in_time'], $currentTime);
            }
            
            // Calculate work hours
            $workHours = calculateWorkHours($attendance['check_in_time'], date('H:i:s'));
            
            // Update attendance record
            $stmt = $pdo->prepare("UPDATE attendance 
                                  SET check_out_time = NOW(), work_hours = ?,
                                      overtime_hours = ?,
                                      overtime_data = ?,
                                      location_data = JSON_MERGE_PATCH(location_data, ?),
                                      device_info = JSON_MERGE_PATCH(device_info, ?),
                                      updated_at = NOW()
                                  WHERE employee_code = ? AND date = CURDATE()");
            $stmt->execute([
                $workHours,
                $overtimeHours,
                json_encode([
                    'overtime_work' => $overtimeData,
                    'validation' => $overtimeValidation,
                    'calculated_at' => date('Y-m-d H:i:s')
                ]),
                json_encode(['checkout_latitude' => $latitude, 'checkout_longitude' => $longitude]),
                json_encode($deviceInfo),
                $employeeCode
            ]);
            
            // Log overtime event if applicable
            if ($isOvertime && $overtimeHours > 0) {
                logAttendanceEvent($employeeCode, 'overtime_checkout', [
                    'overtime_hours' => $overtimeHours,
                    'work_validation' => $overtimeValidation,
                    'overtime_data' => $overtimeData,
                    'location' => ['latitude' => $latitude, 'longitude' => $longitude]
                ], $pdo);
            } else {
                // Log attendance event
                logAttendanceEvent($employeeCode, 'check_out', [
                    'work_hours' => $workHours,
                    'location' => ['latitude' => $latitude, 'longitude' => $longitude],
                    'device' => $deviceInfo
                ], $pdo);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Checked out successfully' . ($isOvertime ? ' with overtime' : ''),
                'check_out_time' => date('h:i A'),
                'work_hours' => $workHours,
                'overtime_hours' => $overtimeHours,
                'overtime_validation' => $overtimeValidation
            ]);
            break;
            
        case 'process_auto_checkouts':
            $currentTime = date('H:i:s');
            $stmt = $pdo->prepare("
                SELECT acl.employee_code, acl.scheduled_time 
                FROM auto_checkout_logs acl 
                JOIN attendance a ON acl.employee_code = a.employee_code AND a.date = CURDATE()
                WHERE acl.checkout_date = CURDATE() 
                  AND acl.actual_time IS NULL 
                  AND a.check_out_time IS NULL 
                  AND (acl.scheduled_time <= ? OR ? >= ?)
            ");
            $stmt->execute([$currentTime, $currentTime, $businessHours['auto_checkout_deadline']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $processed = 0;
            foreach ($rows as $r) { 
                if (processAutoCheckout($r['employee_code'], $pdo)) $processed++; 
            }
            echo json_encode(['success' => true, 'message' => "Processed $processed auto-checkouts"]);
            break;

        case 'auto_absent':
            // Check if already has attendance record for today
            $stmt = $pdo->prepare("SELECT id FROM attendance 
                                  WHERE employee_code = ? AND date = CURDATE()");
            $stmt->execute([$employeeCode]);
            
            if ($stmt->fetch()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Attendance already recorded for today'
                ]);
                break;
            }
            
            // Check if auto-absent record already exists for today
            $stmt = $pdo->prepare("SELECT id FROM attendance 
                                  WHERE employee_code = ? AND date = CURDATE() AND reason LIKE 'Auto-absent%'");
            $stmt->execute([$employeeCode]);
            
            if ($stmt->fetch()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Auto-absent already recorded for today'
                ]);
                break;
            }
            
            // Check if it's after 12:00 PM
            $currentTime = date('H:i:s');
            if (strtotime($currentTime) < strtotime('12:00:00')) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Auto-absent only applies after 12:00 PM'
                ]);
                break;
            }
            
            // Insert auto-absent record
            $stmt = $pdo->prepare("INSERT INTO attendance 
                                  (employee_code, date, status, check_in_time, reason) 
                                  VALUES (?, CURDATE(), 'absent', NOW(), 'Auto-absent: After 12:00 PM without check-in')");
            $stmt->execute([$employeeCode]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Auto-absent recorded',
                'status' => 'absent'
            ]);
            break;
             
        case 'get_time_book':
            // Check if employee code is provided via GET parameter (for demo purposes)
            $demoEmployeeCode = $_GET['employee_code'] ?? null;
            $finalEmployeeCode = $employeeCode ?: $demoEmployeeCode;
            
            if (!$finalEmployeeCode) {
                throw new Exception('Employee code is required');
            }
            
            $stmt = $pdo->prepare("
                SELECT 
                    date,
                    TIME(check_in_time) as check_in_time,
                    TIME(check_out_time) as check_out_time,
                    TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)/60 as total_hours,
                    status
                FROM attendance 
                WHERE employee_code = ?
                ORDER BY date DESC
                LIMIT 30
            ");
            
            $stmt->execute([$finalEmployeeCode]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'records' => $records
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    error_log("Attendance handler error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
