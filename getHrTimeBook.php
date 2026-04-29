<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';

class HRTimeBookManager {
    private $conn;
    private $table_name = "hr_time_book";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function getHRTimeBook($filters = [], $page = 1, $limit = 20) {
        try {
            $whereConditions = [];
            $params = [];
            
            // Build filter conditions
            if (!empty($filters['hr_code'])) {
                $whereConditions[] = "ht.hr_code LIKE ?";
                $params[] = "%{$filters['hr_code']}%";
            }
            
            if (!empty($filters['hr_name'])) {
                $whereConditions[] = "u.full_name LIKE ?";
                $params[] = "%{$filters['hr_name']}%";
            }
            
            if (!empty($filters['date'])) {
                $whereConditions[] = "DATE(ht.check_in) = ?";
                $params[] = $filters['date'];
            }
            
            if (!empty($filters['date_from'])) {
                $whereConditions[] = "DATE(ht.check_in) >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = "DATE(ht.check_in) <= ?";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['status'])) {
                $whereConditions[] = "ht.status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['department'])) {
                $whereConditions[] = "u.department_id = ?";
                $params[] = $filters['department'];
            }
            
            $whereClause = "";
            if (!empty($whereConditions)) {
                $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            }
            
            // Count total records
            $countQuery = "SELECT COUNT(*) as total 
                          FROM {$this->hr_time_book} ht
                          JOIN staff_profiles u ON ht.hr_user_id = u.employee_id
                          {$whereClause}";
            
            $stmt = $this->conn->prepare($countQuery);
            $stmt->execute($params);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Main query
            $offset = ($page - 1) * $limit;
            $query = "SELECT 
                        ht.time_id,
                        ht.hr_id,
                        ht.hr_code,
                        ht.check_in,
                        ht.check_out,
                        ht.total_hours,
                        ht.status,
                        ht.notes,
                        ht.location,
                        ht.ip_address,
                        ht.device_info,
                        ht.created_at,
                        u.full_name as hr_name,
                        u.email as hr_email,
                        u.department_id,
                        d.department_name,
                        u.position,
                        ht.late_minutes,
                        ht.early_departure_minutes,
                        ht.break_time,
                        ht.productive_hours,
                        ht.attendance_score
                    FROM {$this->hr_time_book} ht
                    JOIN staff_profiles u ON ht.hr_user_id = u.employee_id
                    LEFT JOIN departments d ON u.department_id = d.department_id
                    {$whereClause}
                    ORDER BY ht.check_in DESC
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $timeRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format data
            foreach ($timeRecords as &$record) {
                $record['check_in_formatted'] = $record['check_in'] ? 
                    date('M j, Y g:i A', strtotime($record['check_in'])) : 'N/A';
                $record['check_out_formatted'] = $record['check_out'] ? 
                    date('M j, Y g:i A', strtotime($record['check_out'])) : 'N/A';
                $record['date'] = $record['check_in'] ? 
                    date('Y-m-d', strtotime($record['check_in'])) : '';
                $record['check_in_time'] = $record['check_in'] ? 
                    date('g:i A', strtotime($record['check_in'])) : '';
                $record['check_out_time'] = $record['check_out'] ? 
                    date('g:i A', strtotime($record['check_out'])) : '';
                
                // Calculate additional metrics
                $record['is_late'] = $record['late_minutes'] > 0;
                $record['is_early_departure'] = $record['early_departure_minutes'] > 0;
                $record['efficiency_score'] = $record['productive_hours'] && $record['total_hours'] ? 
                    round(($record['productive_hours'] / $record['total_hours']) * 100, 1) : 0;
            }
            
            // Get statistics
            $statistics = $this->getTimeBookStatistics();
            
            return [
                'success' => true,
                'data' => $timeRecords,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => ceil($total / $limit),
                    'total_records' => $total,
                    'limit' => $limit
                ],
                'statistics' => $statistics
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function recordCheckIn($hrData) {
        try {
            $required = ['hr_id', 'hr_code'];
            foreach ($required as $field) {
                if (empty($hrData[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Check if already checked in today
            $checkQuery = "SELECT * FROM {$this->hr_time_book} 
                          WHERE hr_id = ? 
                          AND DATE(check_in) = CURDATE() 
                          AND check_out IS NULL";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$hrData['hr_id']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                throw new Exception("Already checked in for today");
            }
            
            // Calculate if late
            $checkInTime = date('H:i:s');
            $scheduledTime = '09:00:00'; // Default scheduled time
            $lateMinutes = 0;
            
            if (strtotime($checkInTime) > strtotime($scheduledTime)) {
                $lateMinutes = round((strtotime($checkInTime) - strtotime($scheduledTime)) / 60);
            }
            
            // Determine status based on check-in time
            $status = 'present';
            if ($lateMinutes > 60) { // More than 1 hour late
                $status = 'late';
            }
            
            $query = "INSERT INTO {$this->table_name} 
                     (hr_id, hr_code, check_in, status, location, ip_address, device_info, late_minutes, created_at)
                     VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $success = $stmt->execute([
                $hrData['hr_id'],
                $hrData['hr_code'],
                $status,
                $hrData['location'] ?? 'Office',
                $this->getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $lateMinutes
            ]);
            
            if ($success) {
                $timeId = $this->conn->lastInsertId();
                
                // Log check-in activity
                $this->logTimeBookActivity($timeId, 'CHECK_IN', "Checked in at " . date('g:i A'));
                
                return [
                    'success' => true,
                    'message' => 'Successfully checked in',
                    'time_id' => $timeId,
                    'check_in_time' => $checkInTime,
                    'status' => $status,
                    'late_minutes' => $lateMinutes
                ];
            } else {
                throw new Exception("Failed to record check-in");
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function recordCheckOut($timeId, $notes = '') {
        try {
            // Get the check-in record
            $query = "SELECT * FROM {$this->hr_time_book} WHERE time_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$timeId]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                throw new Exception("Time record not found");
            }
            
            if ($record['check_out']) {
                throw new Exception("Already checked out");
            }
            
            $checkOutTime = date('Y-m-d H:i:s');
            $checkInTime = $record['check_in'];
            
            // Calculate total hours
            $totalSeconds = strtotime($checkOutTime) - strtotime($checkInTime);
            $totalHours = round($totalSeconds / 3600, 2);
            
            // Calculate early departure
            $scheduledEndTime = date('Y-m-d') . ' 17:00:00'; // 5 PM
            $earlyDepartureMinutes = 0;
            
            if (strtotime($checkOutTime) < strtotime($scheduledEndTime)) {
                $earlyDepartureMinutes = round((strtotime($scheduledEndTime) - strtotime($checkOutTime)) / 60);
            }
            
            // Calculate productive hours (assuming 1 hour break)
            $breakTime = 1.0; // 1 hour break
            $productiveHours = max(0, $totalHours - $breakTime);
            
            // Calculate attendance score
            $attendanceScore = $this->calculateAttendanceScore(
                $record['late_minutes'], 
                $earlyDepartureMinutes, 
                $productiveHours
            );
            
            $updateQuery = "UPDATE {$this->table_name} 
                           SET check_out = ?, 
                               total_hours = ?,
                               early_departure_minutes = ?,
                               break_time = ?,
                               productive_hours = ?,
                               attendance_score = ?,
                               notes = ?
                           WHERE time_id = ?";
            
            $stmt = $this->conn->prepare($updateQuery);
            $success = $stmt->execute([
                $checkOutTime,
                $totalHours,
                $earlyDepartureMinutes,
                $breakTime,
                $productiveHours,
                $attendanceScore,
                $notes,
                $timeId
            ]);
            
            if ($success) {
                // Log check-out activity
                $this->logTimeBookActivity($timeId, 'CHECK_OUT', "Checked out at " . date('g:i A'));
                
                return [
                    'success' => true,
                    'message' => 'Successfully checked out',
                    'check_out_time' => $checkOutTime,
                    'total_hours' => $totalHours,
                    'productive_hours' => $productiveHours,
                    'attendance_score' => $attendanceScore
                ];
            } else {
                throw new Exception("Failed to record check-out");
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function getTimeBookStatistics() {
        // Today's summary
        $todayQuery = "SELECT 
                      COUNT(*) as total_records,
                      SUM(CASE WHEN check_out IS NULL THEN 1 ELSE 0 END) as currently_checked_in,
                      AVG(late_minutes) as avg_late_minutes,
                      AVG(attendance_score) as avg_attendance_score
                      FROM {$this->table_name}
                      WHERE DATE(check_in) = CURDATE()";
        $stmt = $this->conn->prepare($todayQuery);
        $stmt->execute();
        $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Weekly statistics
        $weeklyQuery = "SELECT 
                       DAYNAME(check_in) as day_name,
                       COUNT(*) as check_ins,
                       AVG(late_minutes) as avg_late_minutes,
                       AVG(total_hours) as avg_hours_worked
                       FROM {$this->table_name}
                       WHERE check_in >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                       GROUP BY DAYNAME(check_in), DATE(check_in)
                       ORDER BY MIN(check_in)";
        $stmt = $this->conn->prepare($weeklyQuery);
        $stmt->execute();
        $weeklyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Department performance
        $deptQuery = "SELECT 
                     d.department_name,
                     COUNT(ht.time_id) as total_records,
                     AVG(ht.attendance_score) as avg_score,
                     AVG(ht.late_minutes) as avg_late_minutes,
                     AVG(ht.total_hours) as avg_hours_worked
                     FROM {$this->table_name} ht
                     JOIN staff_profiles u ON ht.hr_user_id = u.employee_id
                     JOIN departments d ON u.department_id = d.department_id
                     WHERE ht.check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                     GROUP BY d.department_id, d.department_name
                     ORDER BY avg_score DESC";
        $stmt = $this->conn->prepare($deptQuery);
        $stmt->execute();
        $deptStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top performers
        $performerQuery = "SELECT 
                          u.full_name,
                          u.hr_code,
                          AVG(ht.attendance_score) as avg_score,
                          COUNT(ht.time_id) as records_count
                          FROM {$this->hr_time_book} ht
                          JOIN staff_profiles u ON ht.hr_user_id = u.employee_id
                          WHERE ht.check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                          GROUP BY u.user_id, u.full_name, u.hr_code
                          ORDER BY avg_score DESC
                          LIMIT 10";
        $stmt = $this->conn->prepare($performerQuery);
        $stmt->execute();
        $performerStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'today_summary' => $todayStats,
            'weekly_trends' => $weeklyStats,
            'department_performance' => $deptStats,
            'top_performers' => $performerStats
        ];
    }
    
    private function calculateAttendanceScore($lateMinutes, $earlyDepartureMinutes, $productiveHours) {
        $baseScore = 100;
        
        // Deduct for late arrival (2 points per minute after 15 minutes grace)
        if ($lateMinutes > 15) {
            $lateDeduction = ($lateMinutes - 15) * 2;
            $baseScore -= min($lateDeduction, 30); // Max 30 points deduction for lateness
        }
        
        // Deduct for early departure (2 points per minute)
        if ($earlyDepartureMinutes > 0) {
            $earlyDeduction = $earlyDepartureMinutes * 2;
            $baseScore -= min($earlyDeduction, 30); // Max 30 points deduction
        }
        
        // Adjust for productive hours (target: 8 hours)
        if ($productiveHours < 7) {
            $hoursDeduction = (7 - $productiveHours) * 5;
            $baseScore -= min($hoursDeduction, 20); // Max 20 points deduction
        }
        
        return max(0, $baseScore); // Ensure score doesn't go below 0
    }
    
    private function getClientIP() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        
        return $ip;
    }
    
    private function logTimeBookActivity($timeId, $action, $details) {
        $query = "INSERT INTO time_book_activity_log 
                 (time_id, action, details, created_at)
                 VALUES (?, ?, ?, NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$timeId, $action, $details]);
    }
}

// Process request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $database = new Database();
    $db = $database->getConnection();
    $timeBookManager = new HRTimeBookManager($db);
    
    $filters = [
        'hr_code' => $_GET['hr_code'] ?? null,
        'hr_name' => $_GET['hr_name'] ?? null,
        'date' => $_GET['date'] ?? null,
        'date_from' => $_GET['date_from'] ?? null,
        'date_to' => $_GET['date_to'] ?? null,
        'status' => $_GET['status'] ?? null,
        'department' => $_GET['department'] ?? null
    ];
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    
    $result = $timeBookManager->getHRTimeBook($filters, $page, $limit);
    echo json_encode($result);
}
?>