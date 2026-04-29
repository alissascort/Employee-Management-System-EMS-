<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';

class TrainingManager {
    private $conn;
    private $table_name = "trainings";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function getUpcomingTrainings($filters = [], $limit = 10) {
        try {
            $whereConditions = ["t.training_date >= CURDATE()"];
            $params = [];
            
            // Build filter conditions
            if (!empty($filters['department'])) {
                $whereConditions[] = "t.department_id = ?";
                $params[] = $filters['department'];
            }
            
            if (!empty($filters['trainer'])) {
                $whereConditions[] = "t.trainer_id = ?";
                $params[] = $filters['trainer'];
            }
            
            if (!empty($filters['training_type'])) {
                $whereConditions[] = "t.training_type = ?";
                $params[] = $filters['training_type'];
            }
            
            if (!empty($filters['status'])) {
                $whereConditions[] = "t.status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['search'])) {
                $whereConditions[] = "(t.title LIKE ? OR t.description LIKE ? OR tr.full_name LIKE ?)";
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
                        t.training_id,
                        t.title,
                        t.description,
                        t.training_type,
                        t.training_date,
                        t.start_time,
                        t.end_time,
                        t.duration,
                        t.location,
                        t.trainer_id,
                        t.department_id,
                        t.max_participants,
                        t.current_participants,
                        t.status,
                        t.cost,
                        t.materials_path,
                        t.created_at,
                        tr.full_name as trainer_name,
                        tr.email as trainer_email,
                        tr.qualifications as trainer_qualifications,
                        d.department_name,
                        t.prerequisites,
                        t.objectives,
                        t.evaluation_criteria,
                        COUNT(DISTINCT tp.participant_id) as registered_count,
                        t.registration_deadline,
                        t.certification_available
                    FROM {$this->table_name} t
                    LEFT JOIN staff_profiles tr ON t.trainer_id = tr.employee_id
                    LEFT JOIN departments d ON t.department_id = d.department_id
                    LEFT JOIN training_participants tp ON t.training_id = tp.training_id AND tp.status = 'registered'
                    {$whereClause}
                    GROUP BY t.training_id
                    ORDER BY t.training_date ASC, t.start_time ASC
                    LIMIT ?";
            
            $params[] = $limit;
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $trainings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format dates and calculate availability
            foreach ($trainings as &$training) {
                $training['training_date_formatted'] = date('M j, Y', strtotime($training['training_date']));
                $training['time_range'] = date('g:i A', strtotime($training['start_time'])) . ' - ' . 
                                         date('g:i A', strtotime($training['end_time']));
                $training['available_slots'] = $training['max_participants'] - $training['registered_count'];
                $training['is_full'] = $training['available_slots'] <= 0;
                $training['registration_open'] = strtotime($training['registration_deadline']) > time();
                $training['days_until'] = floor((strtotime($training['training_date']) - time()) / (60 * 60 * 24));
            }
            
            // Get training statistics
            $statistics = $this->getTrainingStatistics();
            
            return [
                'success' => true,
                'data' => $trainings,
                'statistics' => $statistics,
                'total_count' => count($trainings)
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function getTrainingStatistics() {
        // Upcoming trainings by department
        $deptQuery = "SELECT 
                     d.department_name,
                     COUNT(t.training_id) as upcoming_trainings
                     FROM trainings t
                     JOIN departments d ON t.department_id = d.department_id
                     WHERE t.training_date >= CURDATE()
                     GROUP BY d.department_id, d.department_name";
        $stmt = $this->conn->prepare($deptQuery);
        $stmt->execute();
        $deptStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Training types distribution
        $typeQuery = "SELECT 
                     training_type,
                     COUNT(*) as count,
                     AVG(cost) as avg_cost
                     FROM trainings
                     WHERE training_date >= CURDATE()
                     GROUP BY training_type";
        $stmt = $this->conn->prepare($typeQuery);
        $stmt->execute();
        $typeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Monthly training schedule
        $monthlyQuery = "SELECT 
                        DATE_FORMAT(training_date, '%Y-%m') as month,
                        COUNT(*) as training_count,
                        SUM(cost) as total_cost
                        FROM trainings
                        WHERE training_date >= CURDATE()
                        GROUP BY DATE_FORMAT(training_date, '%Y-%m')
                        ORDER BY month ASC
                        LIMIT 6";
        $stmt = $this->conn->prepare($monthlyQuery);
        $stmt->execute();
        $monthlyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Trainer workload
        $trainerQuery = "SELECT 
                        u.full_name,
                        COUNT(t.training_id) as assigned_trainings
                        FROM trainings t
                        JOIN staff_profiles u ON t.trainer_id = u.employee_id
                        WHERE t.training_date >= CURDATE()
                        GROUP BY u.employee_id, u.full_name
                        ORDER BY assigned_trainings DESC";
        $stmt = $this->conn->prepare($trainerQuery);
        $stmt->execute();
        $trainerStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'department_distribution' => $deptStats,
            'type_distribution' => $typeStats,
            'monthly_schedule' => $monthlyStats,
            'trainer_workload' => $trainerStats
        ];
    }
    
    public function registerForTraining($trainingId, $employeeId) {
        try {
            // Check if training exists and has available slots
            $trainingQuery = "SELECT max_participants, registration_deadline 
                            FROM trainings 
                            WHERE training_id = ?";
            $stmt = $this->conn->prepare($trainingQuery);
            $stmt->execute([$trainingId]);
            $training = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$training) {
                throw new Exception("Training not found");
            }
            
            if (strtotime($training['registration_deadline']) < time()) {
                throw new Exception("Registration deadline has passed");
            }
            
            // Check current participants
            $participantQuery = "SELECT COUNT(*) as current_count 
                               FROM training_participants 
                               WHERE training_id = ? AND status = 'registered'";
            $stmt = $this->conn->prepare($participantQuery);
            $stmt->execute([$trainingId]);
            $currentCount = $stmt->fetch(PDO::FETCH_ASSOC)['current_count'];
            
            if ($currentCount >= $training['max_participants']) {
                throw new Exception("Training is full");
            }
            
            // Check if already registered
            $checkQuery = "SELECT * FROM training_participants 
                          WHERE training_id = ? AND participant_id = ?";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$trainingId, $employeeId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                throw new Exception("Already registered for this training");
            }
            
            // Register participant
            $registerQuery = "INSERT INTO training_participants 
                             (training_id, participant_id, registration_date, status)
                             VALUES (?, ?, NOW(), 'registered')";
            $stmt = $this->conn->prepare($registerQuery);
            $success = $stmt->execute([$trainingId, $employeeId]);
            
            if ($success) {
                // Update current participants count
                $updateQuery = "UPDATE trainings 
                               SET current_participants = current_participants + 1 
                               WHERE training_id = ?";
                $stmt = $this->conn->prepare($updateQuery);
                $stmt->execute([$trainingId]);
                
                // Log registration
                $this->logTrainingActivity($trainingId, 'REGISTRATION', "User registered for training");
                
                return ['success' => true, 'message' => 'Successfully registered for training'];
            } else {
                throw new Exception("Failed to register for training");
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function createTraining($trainingData) {
        try {
            $required = ['title', 'training_type', 'training_date', 'start_time', 'end_time', 'trainer_id'];
            foreach ($required as $field) {
                if (empty($trainingData[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Calculate duration
            $start = strtotime($trainingData['start_time']);
            $end = strtotime($trainingData['end_time']);
            $duration = round(($end - $start) / 3600, 2);
            
            $query = "INSERT INTO {$this->trainings} 
                     (title, description, training_type, training_date, start_time, end_time, 
                      duration, location, trainer_id, department_id, max_participants, cost,
                      prerequisites, objectives, evaluation_criteria, registration_deadline,
                      certification_available, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', NOW())";
            
            $stmt = $this->conn->prepare($query);
            $success = $stmt->execute([
                $trainingData['title'],
                $trainingData['description'] ?? '',
                $trainingData['training_type'],
                $trainingData['training_date'],
                $trainingData['start_time'],
                $trainingData['end_time'],
                $duration,
                $trainingData['location'] ?? 'TBA',
                $trainingData['trainer_id'],
                $trainingData['department_id'] ?? null,
                $trainingData['max_participants'] ?? 20,
                $trainingData['cost'] ?? 0,
                $trainingData['prerequisites'] ?? '',
                $trainingData['objectives'] ?? '',
                $trainingData['evaluation_criteria'] ?? '',
                $trainingData['registration_deadline'] ?? $trainingData['training_date'],
                $trainingData['certification_available'] ?? 0
            ]);
            
            if ($success) {
                $trainingId = $this->conn->lastInsertId();
                
                // Log training creation
                $this->logTrainingActivity($trainingId, 'CREATED', 'Training session created');
                
                return [
                    'success' => true,
                    'message' => 'Training created successfully',
                    'training_id' => $trainingId
                ];
            } else {
                throw new Exception("Failed to create training");
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function logTrainingActivity($trainingId, $action, $details) {
        $query = "INSERT INTO training_activity_log 
                 (training_id, action, details, created_at)
                 VALUES (?, ?, ?, NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$trainingId, $action, $details]);
    }
}

// Process request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $database = new Database();
    $db = $database->getConnection();
    $trainingManager = new TrainingManager($db);
    
    $filters = [
        'department' => $_GET['department'] ?? null,
        'trainer' => $_GET['trainer'] ?? null,
        'training_type' => $_GET['training_type'] ?? null,
        'status' => $_GET['status'] ?? null,
        'search' => $_GET['search'] ?? null
    ];
    
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    
    $result = $trainingManager->getUpcomingTrainings($filters, $limit);
    echo json_encode($result);
}
?>