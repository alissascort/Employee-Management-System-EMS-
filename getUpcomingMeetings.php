<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';

class MeetingManager {
    private $conn;
    private $table_name = "meetings";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function getUpcomingMeetings($filters = [], $limit = 10) {
        try {
            $whereConditions = ["m.meeting_date >= CURDATE()"];
            $params = [];
            
            // Build filter conditions
            if (!empty($filters['department'])) {
                $whereConditions[] = "m.department_id = ?";
                $params[] = $filters['department'];
            }
            
            if (!empty($filters['organizer'])) {
                $whereConditions[] = "m.organizer_id = ?";
                $params[] = $filters['organizer'];
            }
            
            if (!empty($filters['meeting_type'])) {
                $whereConditions[] = "m.meeting_type = ?";
                $params[] = $filters['meeting_type'];
            }
            
            if (!empty($filters['priority'])) {
                $whereConditions[] = "m.priority = ?";
                $params[] = $filters['priority'];
            }
            
            if (!empty($filters['search'])) {
                $whereConditions[] = "(m.title LIKE ? OR m.description LIKE ? OR o.full_name LIKE ?)";
                $searchTerm = "%{$filters['search']}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $whereClause = "";
            if (!empty($whereConditions)) {
                $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            }
            
            $query = "SELECT 
                        m.meeting_id,
                        m.title,
                        m.description,
                        m.meeting_type,
                        m.meeting_date,
                        m.start_time,
                        m.end_time,
                        m.duration,
                        m.location,
                        m.room_id,
                        m.organizer_id,
                        m.department_id,
                        m.priority,
                        m.status,
                        m.agenda,
                        m.objectives,
                        m.required_attendees,
                        m.optional_attendees,
                        m.meeting_link,
                        m.recording_url,
                        m.materials_path,
                        m.created_at,
                        o.full_name as organizer_name,
                        o.email as organizer_email,
                        d.department_name,
                        r.room_name,
                        r.capacity,
                        COUNT(DISTINCT ma.attendee_id) as confirmed_attendees,
                        m.recurring_pattern,
                        m.recurring_end_date,
                        m.reminder_sent
                    FROM {$this->table_name} m
                    LEFT JOIN staff_profiles o ON m.organizer_id = o.employee_id
                    LEFT JOIN departments d ON m.department_id = d.department_id
                    LEFT JOIN meeting_rooms r ON m.room_id = r.room_id
                    LEFT JOIN meeting_attendees ma ON m.meeting_id = ma.meeting_id AND ma.status = 'accepted'
                    {$whereClause}
                    GROUP BY m.meeting_id
                    ORDER BY 
                        CASE m.priority 
                            WHEN 'high' THEN 1
                            WHEN 'medium' THEN 2
                            WHEN 'low' THEN 3
                            ELSE 4
                        END,
                        m.meeting_date ASC,
                        m.start_time ASC
                    LIMIT ?";
            
            $params[] = $limit;
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format data and calculate additional fields
            foreach ($meetings as &$meeting) {
                $meeting['meeting_date_formatted'] = date('M j, Y', strtotime($meeting['meeting_date']));
                $meeting['time_range'] = date('g:i A', strtotime($meeting['start_time'])) . ' - ' . 
                                        date('g:i A', strtotime($meeting['end_time']));
                $meeting['datetime_full'] = date('D, M j, Y g:i A', strtotime($meeting['meeting_date'] . ' ' . $meeting['start_time']));
                
                // Calculate time until meeting
                $meetingDateTime = strtotime($meeting['meeting_date'] . ' ' . $meeting['start_time']);
                $timeUntil = $meetingDateTime - time();
                $meeting['hours_until'] = round($timeUntil / 3600, 1);
                $meeting['is_urgent'] = $meeting['hours_until'] < 24 && $meeting['priority'] === 'high';
                
                // Parse attendees
                $meeting['required_attendees_list'] = $meeting['required_attendees'] ? 
                    explode(',', $meeting['required_attendees']) : [];
                $meeting['optional_attendees_list'] = $meeting['optional_attendees'] ? 
                    explode(',', $meeting['optional_attendees']) : [];
                
                // Check room availability
                $meeting['room_available'] = $this->checkRoomAvailability(
                    $meeting['room_id'], 
                    $meeting['meeting_date'], 
                    $meeting['start_time'], 
                    $meeting['end_time'],
                    $meeting['meeting_id']
                );
            }
            
            // Get meeting statistics
            $statistics = $this->getMeetingStatistics();
            
            return [
                'success' => true,
                'data' => $meetings,
                'statistics' => $statistics,
                'total_count' => count($meetings)
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function getMeetingStatistics() {
        // Meetings by type
        $typeQuery = "SELECT 
                     meeting_type,
                     COUNT(*) as count,
                     AVG(duration) as avg_duration
                     FROM meetings
                     WHERE meeting_date >= CURDATE()
                     GROUP BY meeting_type";
        $stmt = $this->conn->prepare($typeQuery);
        $stmt->execute();
        $typeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Department-wise meetings
        $deptQuery = "SELECT 
                     d.department_name,
                     COUNT(m.meeting_id) as upcoming_meetings,
                     AVG(m.duration) as avg_duration
                     FROM meetings m
                     JOIN departments d ON m.department_id = d.department_id
                     WHERE m.meeting_date >= CURDATE()
                     GROUP BY d.department_id, d.department_name";
        $stmt = $this->conn->prepare($deptQuery);
        $stmt->execute();
        $deptStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Weekly schedule
        $weeklyQuery = "SELECT 
                       DAYNAME(meeting_date) as day_name,
                       COUNT(*) as meeting_count,
                       AVG(duration) as avg_duration
                       FROM meetings
                       WHERE meeting_date >= CURDATE() AND meeting_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                       GROUP BY DAYNAME(meeting_date), meeting_date
                       ORDER BY meeting_date";
        $stmt = $this->conn->prepare($weeklyQuery);
        $stmt->execute();
        $weeklyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Room utilization
        $roomQuery = "SELECT 
                     r.room_name,
                     COUNT(m.meeting_id) as booked_slots,
                     r.capacity
                     FROM meeting_rooms r
                     LEFT JOIN meetings m ON r.room_id = m.room_id AND m.meeting_date >= CURDATE()
                     GROUP BY r.room_id, r.room_name, r.capacity
                     ORDER BY booked_slots DESC";
        $stmt = $this->conn->prepare($roomQuery);
        $stmt->execute();
        $roomStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'type_distribution' => $typeStats,
            'department_distribution' => $deptStats,
            'weekly_schedule' => $weeklyStats,
            'room_utilization' => $roomStats
        ];
    }
    
    public function scheduleMeeting($meetingData) {
        try {
            $required = ['title', 'meeting_date', 'start_time', 'end_time', 'organizer_id'];
            foreach ($required as $field) {
                if (empty($meetingData[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Calculate duration
            $start = strtotime($meetingData['start_time']);
            $end = strtotime($meetingData['end_time']);
            $duration = round(($end - $start) / 3600, 2);
            
            // Check room availability
            if (!empty($meetingData['room_id'])) {
                $roomAvailable = $this->checkRoomAvailability(
                    $meetingData['room_id'],
                    $meetingData['meeting_date'],
                    $meetingData['start_time'],
                    $meetingData['end_time']
                );
                
                if (!$roomAvailable) {
                    throw new Exception("Selected room is not available for the requested time slot");
                }
            }
            
            $query = "INSERT INTO {$this->meetings} 
                     (title, description, meeting_type, meeting_date, start_time, end_time, 
                      duration, location, room_id, organizer_id, department_id, priority, 
                      agenda, objectives, required_attendees, optional_attendees, meeting_link,
                      recurring_pattern, recurring_end_date, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', NOW())";
            
            $stmt = $this->conn->prepare($query);
            $success = $stmt->execute([
                $meetingData['title'],
                $meetingData['description'] ?? '',
                $meetingData['meeting_type'] ?? 'general',
                $meetingData['meeting_date'],
                $meetingData['start_time'],
                $meetingData['end_time'],
                $duration,
                $meetingData['location'] ?? '',
                $meetingData['room_id'] ?? null,
                $meetingData['organizer_id'],
                $meetingData['department_id'] ?? null,
                $meetingData['priority'] ?? 'medium',
                $meetingData['agenda'] ?? '',
                $meetingData['objectives'] ?? '',
                $meetingData['required_attendees'] ?? '',
                $meetingData['optional_attendees'] ?? '',
                $meetingData['meeting_link'] ?? '',
                $meetingData['recurring_pattern'] ?? null,
                $meetingData['recurring_end_date'] ?? null
            ]);
            
            if ($success) {
                $meetingId = $this->conn->lastInsertId();
                
                // Add attendees
                $this->addMeetingAttendees($meetingId, $meetingData);
                
                // Send notifications
                $this->sendMeetingInvitations($meetingId);
                
                // Log meeting creation
                $this->logMeetingActivity($meetingId, 'SCHEDULED', 'Meeting scheduled');
                
                return [
                    'success' => true,
                    'message' => 'Meeting scheduled successfully',
                    'meeting_id' => $meetingId
                ];
            } else {
                throw new Exception("Failed to schedule meeting");
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function checkRoomAvailability($roomId, $date, $startTime, $endTime, $excludeMeetingId = null) {
        $query = "SELECT COUNT(*) as conflict_count 
                 FROM meetings 
                 WHERE room_id = ? 
                 AND meeting_date = ? 
                 AND status != 'cancelled'
                 AND ((start_time BETWEEN ? AND ?) OR (end_time BETWEEN ? AND ?) OR (start_time <= ? AND end_time >= ?))";
        
        $params = [$roomId, $date, $startTime, $endTime, $startTime, $endTime, $startTime, $endTime];
        
        if ($excludeMeetingId) {
            $query .= " AND meeting_id != ?";
            $params[] = $excludeMeetingId;
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['conflict_count'] == 0;
    }
    
    private function addMeetingAttendees($meetingId, $meetingData) {
        // Add required attendees
        if (!empty($meetingData['required_attendees'])) {
            $attendees = explode(',', $meetingData['required_attendees']);
            foreach ($attendees as $attendeeId) {
                $this->addAttendee($meetingId, trim($attendeeId), 'required');
            }
        }
        
        // Add optional attendees
        if (!empty($meetingData['optional_attendees'])) {
            $attendees = explode(',', $meetingData['optional_attendees']);
            foreach ($attendees as $attendeeId) {
                $this->addAttendee($meetingId, trim($attendeeId), 'optional');
            }
        }
    }
    
    private function addAttendee($meetingId, $employeeId, $type) {
        $query = "INSERT INTO meeting_attendees 
                 (meeting_id, attendee_id, attendee_type, invitation_sent, created_at)
                 VALUES (?, ?, ?, 0, NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$meetingId, $employeeId, $type]);
    }
    
    private function sendMeetingInvitations($meetingId) {
        // Implementation for sending email/notification invitations
        // This would integrate with your email system or notification service
        error_log("Sending invitations for meeting: $meetingId");
        
        // Update invitation sent status
        $query = "UPDATE meeting_attendees 
                 SET invitation_sent = 1, invitation_sent_at = NOW() 
                 WHERE meeting_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$meetingId]);
    }
    
    private function logMeetingActivity($meetingId, $action, $details) {
        $query = "INSERT INTO meeting_activity_log 
                 (meeting_id, action, details, created_at)
                 VALUES (?, ?, ?, NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$meetingId, $action, $details]);
    }
}

// Process request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $database = new Database();
    $db = $database->getConnection();
    $meetingManager = new MeetingManager($db);
    
    $filters = [
        'department' => $_GET['department'] ?? null,
        'organizer' => $_GET['organizer'] ?? null,
        'meeting_type' => $_GET['meeting_type'] ?? null,
        'priority' => $_GET['priority'] ?? null,
        'search' => $_GET['search'] ?? null
    ];
    
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    
    $result = $meetingManager->getUpcomingMeetings($filters, $limit);
    echo json_encode($result);
}
?>