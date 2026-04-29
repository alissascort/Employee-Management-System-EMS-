<?php
/**
 * CSO Attendance Handler
 * Handles check_in, check_out, and get_today_status
 * Updated: full attendance policy, geolocation, work_hours, status fix
 * Updated: Improved checkout logic - allows early/overtime checkout with reasons
 */

session_set_cookie_params([
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

header('Content-Type: application/json');
require_once 'db_connect.php';

// ── Auth check ──────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// ── Route: GET ?action=get_today_status ─────────────────────────────────
// Allow GET for status check (does not modify data)
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// For POST actions, read JSON body
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? $action;
}

// ── DB connection ────────────────────────────────────────────────────────
try {
    $db   = new Database();
    $conn = $db->connect();
} catch (Exception $e) {
    error_log('CSO attendance DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// ── Helper: resolve cso_code from session ────────────────────────────────
function resolveCsoCode($conn) {
    // Try session cache first
    if (!empty($_SESSION['cso_code'])) {
        return $_SESSION['cso_code'];
    }
    // Fall back to looking up by user_id
    if (!empty($_SESSION['user_id'])) {
        $s = $conn->prepare("SELECT cso_code FROM csos WHERE cso_id = ? LIMIT 1");
        $s->execute([$_SESSION['user_id']]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if ($r && $r['cso_code']) {
            $_SESSION['cso_code'] = $r['cso_code']; // cache it
            return $r['cso_code'];
        }
    }
    return null;
}

// ── Helper: determine attendance status from check-in time ───────────────
// Matches the same policy as employee dashboard:
//   Present:      07:45 – 08:30
//   Present/Late: 08:31 – 10:00
//   Late:         10:01 – 11:59
//   Absent:       12:00+
function determineAttendanceStatus($timeString) {
    $t = strtotime($timeString);
    $h = (int) date('H', $t);
    $i = (int) date('i', $t);
    $totalMinutes = $h * 60 + $i;

    $PRESENT_START  = 7  * 60 + 45;  // 07:45
    $PRESENT_END    = 8  * 60 + 30;  // 08:30
    $LATE_END       = 10 * 60;       // 10:00
    $AUTO_ABSENT    = 12 * 60;       // 12:00

    if ($totalMinutes >= $PRESENT_START && $totalMinutes <= $PRESENT_END) {
        return 'present';
    }
    if ($totalMinutes <= $LATE_END) {
        return 'present_late';
    }
    if ($totalMinutes < $AUTO_ABSENT) {
        return 'late';
    }
    return 'absent';
}

// ── Helper: calculate work hours (minus 1hr lunch if spanning 12-13) ─────
function calculateWorkHours($checkIn, $checkOut) {
    $inTs  = strtotime($checkIn);
    $outTs = strtotime($checkOut);
    if (!$inTs || !$outTs || $outTs <= $inTs) return 0;

    $totalHours = ($outTs - $inTs) / 3600;

    // Deduct 1 hour lunch if session spans noon break
    $lunchStart = strtotime(date('Y-m-d', $inTs) . ' 12:00:00');
    $lunchEnd   = strtotime(date('Y-m-d', $inTs) . ' 13:00:00');
    if ($inTs < $lunchStart && $outTs > $lunchEnd) {
        $totalHours -= 1;
    }

    return round(max(0, $totalHours), 2);
}

// ════════════════════════════════════════════════════════════════════════
//  ACTION ROUTING
// ════════════════════════════════════════════════════════════════════════

try {

    // ── GET TODAY STATUS ─────────────────────────────────────────────────
    if ($action === 'get_today_status') {

        $csoCode = resolveCsoCode($conn);

        if (!$csoCode) {
            echo json_encode(['success' => false, 'message' => 'Could not determine CSO code from session']);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT * FROM attendance
            WHERE employee_code = ? AND date = CURDATE()
            LIMIT 1
        ");
        $stmt->execute([$csoCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            echo json_encode([
                'success'        => true,
                'checked_in'     => true,
                'checked_out'    => !is_null($row['check_out_time']),
                'status'         => $row['status'],
                'check_in_time'  => $row['check_in_time']
                    ? date('h:i A', strtotime($row['check_in_time']))
                    : null,
                'check_out_time' => $row['check_out_time']
                    ? date('h:i A', strtotime($row['check_out_time']))
                    : null,
                'work_hours'     => $row['work_hours']
            ]);
        } else {
            echo json_encode(['success' => true, 'checked_in' => false]);
        }
        exit;
    }

    // ── POST ACTIONS: require POST method ────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit;
    }

    // ── Read and validate common POST fields ─────────────────────────────
    $csoCodeInput = strtoupper(trim($input['cso_code'] ?? ''));
    $latitude     = isset($input['latitude'])  ? (float) $input['latitude']  : null;
    $longitude    = isset($input['longitude']) ? (float) $input['longitude'] : null;
    $deviceInfo   = $input['device']           ?? null;
    $reason       = trim($input['reason']      ?? '');
    $status       = trim($input['status']      ?? '');

    if (empty($action) || empty($csoCodeInput)) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit;
    }

    // Format check
    if (!preg_match('/^[0-9]{4}\/CSO\/[0-9]{4}$/', $csoCodeInput)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSO Code format. Use: YYYY/CSO/XXXX']);
        exit;
    }

    // Fetch CSO record with department name via JOIN
    $stmt = $conn->prepare("
        SELECT c.cso_id, c.cso_code, c.full_name, d.name as department
        FROM csos c
        LEFT JOIN departments d ON c.department_id = d.department_id
        WHERE c.cso_code = ? AND c.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$csoCodeInput]);
    $cso = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cso) {
        echo json_encode(['success' => false, 'message' => 'CSO Code not found or account inactive']);
        exit;
    }

    // ── Security: code must belong to logged-in user ─────────────────────
    if ((int)$cso['cso_id'] !== (int)$_SESSION['user_id']) {
        error_log('SECURITY: session user_id=' . $_SESSION['user_id'] .
                  ' tried to use cso_code belonging to cso_id=' . $cso['cso_id']);
        echo json_encode([
            'success' => false,
            'message' => 'This CSO code does not belong to your account'
        ]);
        exit;
    }

    // Cache in session
    $_SESSION['cso_code'] = $cso['cso_code'];

    $currentDate     = date('Y-m-d');
    $currentTime     = date('H:i:s');
    $currentDateTime = $currentDate . ' ' . $currentTime;

    // ── CHECK IN ─────────────────────────────────────────────────────────
    if ($action === 'check_in') {

        // Already checked in today?
        $stmt = $conn->prepare("
            SELECT id FROM attendance
            WHERE employee_code = ? AND date = ?
            LIMIT 1
        ");
        $stmt->execute([$cso['cso_code'], $currentDate]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'You have already checked in today']);
            exit;
        }

        // Determine status from actual time (ignore frontend-sent status for security)
        $attendanceStatus = determineAttendanceStatus($currentDateTime);

        // After 12:00 PM → auto-absent, block check-in
        if ($attendanceStatus === 'absent') {
            echo json_encode([
                'success' => false,
                'message' => 'Check-in is no longer available after 12:00 PM. You have been marked as absent.'
            ]);
            exit;
        }

        // Reason required for late check-in
        if ($attendanceStatus === 'late' && empty($reason)) {
            echo json_encode([
                'success' => false,
                'message' => 'A reason is required for late check-in'
            ]);
            exit;
        }

        // Build location JSON
        $locationData = json_encode([
            'latitude'         => $latitude,
            'longitude'        => $longitude,
            'validation_passed'=> ($latitude !== null && $longitude !== null)
        ]);

        // Insert attendance record
        $stmt = $conn->prepare("
            INSERT INTO attendance
                (employee_code, date, status, check_in_time, reason, location_data, device_info, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $cso['cso_code'],
            $currentDate,
            $attendanceStatus,
            $currentDateTime,
            $reason,
            $locationData,
            json_encode($deviceInfo)
        ]);

        // Log CSO activity
        $logStmt = $conn->prepare("
            INSERT INTO cso_activity_logs (cso_id, activity_type, description)
            VALUES (?, 'cso_check_in', ?)
        ");
        $logStmt->execute([
            $cso['cso_id'],
            "CSO checked in: {$cso['full_name']} ({$cso['cso_code']}) — status: {$attendanceStatus}"
        ]);

        $statusLabels = [
            'present'      => 'Present',
            'present_late' => 'Present / Late',
            'late'         => 'Late',
        ];

        echo json_encode([
            'success'        => true,
            'message'        => 'Check IN successful! Welcome, ' . $cso['full_name'],
            'cso_name'       => $cso['full_name'],
            'check_in_time'  => date('h:i A', strtotime($currentDateTime)),
            'status'         => $attendanceStatus,
            'status_label'   => $statusLabels[$attendanceStatus] ?? $attendanceStatus
        ]);
        exit;
    }

    // ── CHECK OUT (COMPLETE WITH REASONS FOR ALL SCENARIOS) ──────────────────
if ($action === 'check_out') {

    // Must be checked in, not yet checked out
    $stmt = $conn->prepare("
        SELECT id, check_in_time, check_out_time, status
        FROM attendance
        WHERE employee_code = ? AND date = ?
        LIMIT 1
    ");
    $stmt->execute([$cso['cso_code'], $currentDate]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attendance) {
        echo json_encode(['success' => false, 'message' => 'You have not checked in today']);
        exit;
    }

    if (!is_null($attendance['check_out_time'])) {
        echo json_encode(['success' => false, 'message' => 'You have already checked out today']);
        exit;
    }

    // ── CHECKOUT LOGIC WITH REASONS FOR ALL SCENARIOS ──────────────────────
    $totalMinutes = (int) date('H') * 60 + (int) date('i');
    $checkoutStart = 16 * 60 + 15;  // 4:15 PM
    $checkoutEnd = 19 * 60;          // 7:00 PM
    $midnight = 24 * 60;             // 12:00 AM

    $checkoutType = 'normal';
    $requiresReason = false;
    $reasonPrompt = '';
    $warningMessage = '';

    // Determine checkout type and reason requirements
    if ($totalMinutes < $checkoutStart) {
        // EARLY CHECKOUT - ALWAYS REQUIRES REASON
        $requiresReason = true;
        $checkoutType = 'early';
        $reasonPrompt = 'Early departure reason';
        
        if (empty($reason)) {
            echo json_encode([
                'success' => false,
                'message' => 'Early checkout requires a reason. Please provide a reason (e.g., Medical appointment, Emergency, Personal matter)'
            ]);
            exit;
        }
        $warningMessage = '⚠️ Early checkout recorded. Reason: ' . htmlspecialchars($reason);
        
    } elseif ($totalMinutes > $checkoutEnd && $totalMinutes < $midnight) {
        // OVERTIME CHECKOUT - SHOULD ALSO REQUIRE REASON
        $requiresReason = true;
        $checkoutType = 'overtime';
        $reasonPrompt = 'Overtime reason';
        
        if (empty($reason)) {
            echo json_encode([
                'success' => false,
                'message' => 'Overtime checkout requires a reason. Please provide details (e.g., Project deadline, Emergency maintenance, Critical issue)'
            ]);
            exit;
        }
        $warningMessage = '✓ Overtime checkout recorded. Reason: ' . htmlspecialchars($reason) . ' Thank you for the extra effort!';
        
    } elseif ($totalMinutes >= $checkoutStart && $totalMinutes <= $checkoutEnd) {
        // NORMAL CHECKOUT - NO REASON REQUIRED
        $checkoutType = 'normal';
        $requiresReason = false;
        $warningMessage = '✓ Checkout successful. Have a great evening!';
        
    } else {
        // AFTER MIDNIGHT - SYSTEM AUTO (should not happen manually)
        $checkoutType = 'late_night';
        $warningMessage = '⚠️ Late night checkout recorded. System will log this.';
    }

    // Calculate work hours
    $workHours = calculateWorkHours($attendance['check_in_time'], $currentDateTime);

    // Build checkout location JSON with all details
    $checkoutLocation = json_encode([
        'checkout_latitude'  => $latitude,
        'checkout_longitude' => $longitude,
        'checkout_type'      => $checkoutType,
        'checkout_reason'    => $reason ?? null,
        'checkout_time'      => $currentDateTime
    ]);

    // Prepare notes field with complete checkout information
    $notesText = "Checkout type: {$checkoutType}";
    if (!empty($reason)) {
        $notesText .= " | Reason: " . $reason;
    }
    if ($latitude && $longitude) {
        $notesText .= " | Location: {$latitude}, {$longitude}";
    }

    // Update attendance record
    $stmt = $conn->prepare("
        UPDATE attendance
        SET check_out_time = ?,
            work_hours     = ?,
            location_data  = JSON_MERGE_PATCH(COALESCE(location_data, '{}'), ?),
            device_info    = JSON_MERGE_PATCH(COALESCE(device_info, '{}'), ?),
            updated_at     = NOW(),
            notes          = CONCAT(COALESCE(notes, ''), ?)
        WHERE id = ?
    ");
    $stmt->execute([
        $currentDateTime,
        $workHours,
        $checkoutLocation,
        json_encode(['checkout_device' => $deviceInfo]),
        " | " . $notesText,
        $attendance['id']
    ]);

    // Log CSO activity with all details
    $logStmt = $conn->prepare("
        INSERT INTO cso_activity_logs (cso_id, activity_type, description)
        VALUES (?, 'cso_check_out', ?)
    ");
    $logStmt->execute([
        $cso['cso_id'],
        "CSO checked out: {$cso['full_name']} ({$cso['cso_code']}) — {$workHours} hrs worked — Type: {$checkoutType}" . ($reason ? " — Reason: {$reason}" : "")
    ]);

    echo json_encode([
        'success'         => true,
        'message'         => $warningMessage . ' Goodbye, ' . $cso['full_name'],
        'cso_name'        => $cso['full_name'],
        'check_out_time'  => date('h:i A', strtotime($currentDateTime)),
        'work_hours'      => $workHours,
        'checkout_type'   => $checkoutType,
        'reason_recorded' => !empty($reason)
    ]);
    exit;
}

    // ── Unknown action ───────────────────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);

} catch (Exception $e) {
    error_log('CSO attendance handler error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred',
        'debug'   => $e->getMessage()
    ]);
}
?>
