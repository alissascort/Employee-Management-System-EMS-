<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config/database.php';

class LeaveRequestManager {
    private $conn;
    private $table_name = "leave_requests";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function createLeaveRequest($leaveData) {
        try {
            // Validate required fields
            $required = ['employee_id', 'leave_type', 'start_date', 'end_date', 'reason'];
            foreach ($required as $field) {
                if (empty($leaveData[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Validate dates
            $startDate = strtotime($leaveData['start_date']);
            $endDate = strtotime($leaveData['end_date']);
            
            if ($startDate === false || $endDate === false) {
                throw new Exception("Invalid date format");
            }
            
            if ($endDate < $startDate) {
                throw new Exception("End date cannot be before start date");
            }
            
            // Check if dates are in the future
            if ($startDate < time()) {
                throw new Exception("Leave cannot be requested for past dates");
            }
            
            // Calculate number of days
            $days = $this->calculateWorkingDays($leaveData['start_date'], $leaveData['end_date']);
            
            if ($days <= 0) {
                throw new Exception("Invalid leave duration");
            }
            
            // Check leave balance
            $balanceCheck = $this->checkLeaveBalance($leaveData['employee_id'], $leaveData['leave_type'], $days);
            if (!$balanceCheck['success']) {
                throw new Exception($balanceCheck['message']);
            }
            
            // Check for overlapping leave requests
            if ($this->hasOverlappingLeave($leaveData['employee_id'], $leaveData['start_date'], $leaveData['end_date'])) {
                throw new Exception("Overlapping leave request exists");
            }
            
            // Begin transaction
            $this->conn->beginTransaction();
            
            try {
                // Create leave request
                $leaveRequestId = $this->insertLeaveRequest($leaveData, $days);
                
                // Update leave balance (reserve the days)
                $this->reserveLeaveDays($leaveData['employee_id'], $leaveData['leave_type'], $days);
                
                // Notify manager
                $this->notifyManager($leaveRequestId);
                
                // Log leave request creation
                $this->logLeaveActivity($leaveRequestId, 'REQUEST_CREATED', 'Leave request submitted');
                
                // Commit transaction
                $this->conn->commit();
                
                return [
                    'success' => true,
                    'message' => 'Leave request submitted successfully',
                    'leave_request_id' => $leaveRequestId,
                    'days_requested' => $days,
                    'status' => 'pending',
                    'balance_remaining' => $balanceCheck['remaining'] - $days
                ];
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw new Exception("Failed to create leave request: " . $e->getMessage());
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function calculateWorkingDays($startDate, $endDate) {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('+1 day'); // Include end date
        
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);
        
        $days = 0;
        foreach ($period as $date) {
            // Skip weekends (Saturday = 6, Sunday = 7)
            if ($date->format('N') < 6) {
                $days++;
            }
        }
        
        return $days;
    }
    
    private function checkLeaveBalance($employeeId, $leaveType, $requestedDays) {
        $query = "SELECT remaining_days 
                 FROM employee_leave_balance 
                 WHERE employee_id = ? AND leave_type = ? AND fiscal_year = YEAR(CURDATE())";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$employeeId, $leaveType]);
        $balance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$balance) {
            return ['success' => false, 'message' => 'No leave balance found for this type'];
        }
        
        $remaining = $balance['remaining_days'];
        
        if ($remaining < $requestedDays) {
            return [
                'success' => false, 
                'message' => "Insufficient leave balance. Requested: $requestedDays, Available: $remaining"
            ];
        }
        
        // Check for maximum consecutive days
        $maxConsecutive = $this->getMaxConsecutiveDays($leaveType);
        if ($requestedDays > $maxConsecutive) {
            return [
                'success' => false,
                'message' => "Maximum consecutive days for $leaveType is $maxConsecutive"
            ];
        }
        
        return [
            'success' => true,
            'remaining' => $remaining
        ];
    }
    
    private function getMaxConsecutiveDays($leaveType) {
        $limits = [
            'Vacation' => 14,
            'Sick Leave' => 7,
            'Personal Leave' => 5,
            'Maternity/Paternity' => 90,
            'Bereavement' => 5
        ];
        
        return $limits[$leaveType] ?? 7;
    }
    
    private function hasOverlappingLeave($employeeId, $startDate, $endDate) {
        $query = "SELECT COUNT(*) as overlap_count 
                 FROM {$this->table_name} 
                 WHERE employee_id = ? 
                 AND status IN ('pending', 'approved')
                 AND ((start_date BETWEEN ? AND ?) 
                      OR (end_date BETWEEN ? AND ?) 
                      OR (? BETWEEN start_date AND end_date) 
                      OR (? BETWEEN start_date AND end_date))";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $employeeId,
            $startDate, $endDate,
            $startDate, $endDate,
            $startDate, $endDate
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['overlap_count'] > 0;
    }
    
    private function insertLeaveRequest($data, $days) {
        $query = "INSERT INTO {$this->table_name} 
                 (employee_id, leave_type, start_date, end_date, days, reason, 
                  contact_info, handover_notes, emergency_contact, status, 
                  applied_date, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', CURDATE(), NOW())";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $data['employee_id'],
            $data['leave_type'],
            $data['start_date'],
            $data['end_date'],
            $days,
            $data['reason'],
            $data['contact_info'] ?? '',
            $data['handover_notes'] ?? '',
            $data['emergency_contact'] ?? ''
        ]);
        
        return $this->conn->lastInsertId();
    }
    
    private function reserveLeaveDays($employeeId, $leaveType, $days) {
        $query = "UPDATE employee_leave_balance 
                 SET reserved_days = reserved_days + ?,
                     remaining_days = remaining_days - ?
                 WHERE employee_id = ? AND leave_type = ? AND fiscal_year = YEAR(CURDATE())";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$days, $days, $employeeId, $leaveType]);
    }
    
    private function notifyManager($leaveRequestId) {
        // Get leave request details
        $query = "SELECT lr.*, e.first_name, e.last_name, e.department_id, 
                         d.department_name, m.user_id as manager_id
                 FROM {$this->table_name} lr
                 JOIN employees e ON lr.employee_id = e.employee_id
                 JOIN departments d ON e.department_id = d.department_id
                 LEFT JOIN users m ON d.department_id = m.department_id AND m.role = 'manager'
                 WHERE lr.leave_request_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$leaveRequestId]);
        $leaveRequest = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($leaveRequest && $leaveRequest['manager_id']) {
            // Create notification for manager
            $this->createManagerNotification($leaveRequest['manager_id'], $leaveRequest);
            
            // Send email notification (in real implementation)
            $this->sendManagerEmail($leaveRequest);
        }
    }
    
    private function createManagerNotification($managerId, $leaveRequest) {
        $message = "New leave request from {$leaveRequest['first_name']} {$leaveRequest['last_name']} " .
                  "for {$leaveRequest['days']} days ({$leaveRequest['leave_type']})";
        
        $query = "INSERT INTO notifications 
                 (user_id, title, message, type, related_id, status, created_at)
                 VALUES (?, 'New Leave Request', ?, 'leave_request', ?, 'unread', NOW())";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$managerId, $message, $leaveRequest['leave_request_id']]);
    }
    
    private function sendManagerEmail($leaveRequest) {
        // Implementation for sending email to manager
        // This would integrate with your email system
        
        $subject = "New Leave Request - {$leaveRequest['first_name']} {$leaveRequest['last_name']}";
        $message = "
        A new leave request has been submitted:
        
        Employee: {$leaveRequest['first_name']} {$leaveRequest['last_name']}
        Department: {$leaveRequest['department_name']}
        Leave Type: {$leaveRequest['leave_type']}
        Period: {$leaveRequest['start_date']} to {$leaveRequest['end_date']}
        Duration: {$leaveRequest['days']} days
        Reason: {$leaveRequest['reason']}
        
        Please review and approve/deny the request in the HR system.
        
        Login to HR System: https://yourcompany.com/hr
        ";
        
        // In real implementation, send email to manager
        error_log("Manager notification email would be sent for leave request: {$leaveRequest['leave_request_id']}");
    }
    
    private function logLeaveActivity($leaveRequestId, $action, $details) {
        $query = "INSERT INTO leave_activity_log 
                 (leave_request_id, action, details, created_at)
                 VALUES (?, ?, ?, NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$leaveRequestId, $action, $details]);
    }
    
    public function getLeaveBalance($employeeId) {
        $query = "SELECT leave_type, total_days, used_days, reserved_days, remaining_days
                 FROM employee_leave_balance 
                 WHERE employee_id = ? AND fiscal_year = YEAR(CURDATE())
                 ORDER BY leave_type";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Process request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    $leaveManager = new LeaveRequestManager($db);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($input) {
        $result = $leaveManager->createLeaveRequest($input);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    }
}
?>