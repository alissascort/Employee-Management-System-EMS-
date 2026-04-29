<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';

class RecruitmentAPI {
    private $recruitmentManager;
    
    public function __construct() {
        $database = new Database();
        $db = $database->getConnection();
        $this->recruitmentManager = new RecruitmentManager($db);
    }
    
    public function processRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        switch ($method) {
            case 'GET':
                $this->getRecruitmentData();
                break;
            case 'POST':
                $this->addApplication();
                break;
            case 'PUT':
                $this->updateApplication();
                break;
            case 'DELETE':
                $this->deleteApplication();
                break;
            default:
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    
    private function getRecruitmentData() {
        try {
            $filters = [
                'status' => $_GET['status'] ?? null,
                'department' => $_GET['department'] ?? null,
                'position' => $_GET['position'] ?? null,
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null,
                'search' => $_GET['search'] ?? null
            ];
            
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            
            $result = $this->recruitmentManager->getApplications($filters, $page, $limit);
            
            // Add analytics data
            $analytics = $this->recruitmentManager->getRecruitmentAnalytics();
            
            echo json_encode([
                'success' => true,
                'data' => $result['data'],
                'pagination' => $result['pagination'],
                'analytics' => $analytics,
                'total_count' => $result['total_count']
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    private function addApplication() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $required = ['position', 'applicant_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            $applicationId = $this->recruitmentManager->addApplication($data);
            
            if ($applicationId) {
                // Log activity
                $this->recruitmentManager->logActivity($applicationId, 'APPLICATION_CREATED', 'New application submitted');
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Application added successfully',
                    'application_id' => $applicationId
                ]);
            } else {
                throw new Exception("Failed to add application");
            }
            
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    private function updateApplication() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['application_id'])) {
                throw new Exception("Application ID is required");
            }
            
            $success = $this->recruitmentManager->updateApplication($data['application_id'], $data);
            
            if ($success) {
                // Log status change if applicable
                if (isset($data['status'])) {
                    $this->recruitmentManager->logActivity($data['application_id'], 'STATUS_CHANGED', "Status changed to: {$data['status']}");
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Application updated successfully'
                ]);
            } else {
                throw new Exception("Failed to update application");
            }
            
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    private function deleteApplication() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['application_id'])) {
                throw new Exception("Application ID is required");
            }
            
            $success = $this->recruitmentManager->deleteApplication($data['application_id']);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Application deleted successfully'
                ]);
            } else {
                throw new Exception("Failed to delete application");
            }
            
        } catch (Exception $e) {
            http_responsecode(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}

// Supporting Classes
class RecruitmentManager {
    private $conn;
    private $table_name = "recruitment_applications";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function getApplications($filters = [], $page = 1, $limit = 10) {
        $whereConditions = [];
        $params = [];
        
        // Build WHERE conditions - UPDATED to match your actual database schema
        if (!empty($filters['status'])) {
            $whereConditions[] = "ra.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['department'])) {
            $whereConditions[] = "e.department = ?";
            $params[] = $filters['department'];
        }
        
        if (!empty($filters['position'])) {
            $whereConditions[] = "ra.position = ?";
            $params[] = $filters['position'];
        }
        
        if (!empty($filters['date_from'])) {
            $whereConditions[] = "ra.application_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $whereConditions[] = "ra.application_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['search'])) {
            $whereConditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR ra.position LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = "";
        if (!empty($whereConditions)) {
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
        }
        
        // Count total records - UPDATED to match your schema
        $countQuery = "SELECT COUNT(*) as total 
                      FROM {$this->table_name} ra
                      LEFT JOIN employees e ON ra.applicant_id = e.employee_id
                      {$whereClause}";
        
        $stmt = $this->conn->prepare($countQuery);
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Main query - UPDATED to match your actual database schema
        $offset = ($page - 1) * $limit;
        $query = "SELECT 
                    ra.id as application_id,
                    ra.position as position_applied,
                    e.first_name,
                    e.last_name,
                    CONCAT(e.first_name, ' ', e.last_name) as applicant_name,
                    e.email,
                    e.phone,
                    ra.application_date,
                    ra.status,
                    ra.notes,
                    e.department,
                    ra.created_at,
                    ra.updated_at
                FROM {$this->table_name} ra
                LEFT JOIN employees e ON ra.applicant_id = e.employee_id
                {$whereClause}
                ORDER BY ra.application_date DESC
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate pagination
        $totalPages = ceil($total / $limit);
        
        return [
            'data' => $applications,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_records' => $total,
                'limit' => $limit
            ],
            'total_count' => $total
        ];
    }
    
    public function getRecruitmentAnalytics() {
        // Status distribution - UPDATED for your schema
        $statusQuery = "SELECT status, COUNT(*) as count 
                       FROM {$this->table_name} 
                       GROUP BY status";
        $stmt = $this->conn->prepare($statusQuery);
        $stmt->execute();
        $statusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Monthly applications - UPDATED for your schema
        $monthlyQuery = "SELECT 
                        DATE_FORMAT(application_date, '%Y-%m') as month,
                        COUNT(*) as count
                        FROM {$this->table_name}
                        WHERE application_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                        GROUP BY DATE_FORMAT(application_date, '%Y-%m')
                        ORDER BY month DESC";
        $stmt = $this->conn->prepare($monthlyQuery);
        $stmt->execute();
        $monthlyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Position statistics - UPDATED for your schema
        $positionQuery = "SELECT 
                         ra.position,
                         COUNT(ra.id) as application_count,
                         AVG(CASE WHEN ra.status = 'Accepted' THEN 1 ELSE 0 END) as hire_rate
                         FROM {$this->table_name} ra
                         GROUP BY ra.position";
        $stmt = $this->conn->prepare($positionQuery);
        $stmt->execute();
        $positionStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'status_distribution' => $statusStats,
            'monthly_trends' => $monthlyStats,
            'position_analytics' => $positionStats
        ];
    }
    
    public function addApplication($data) {
        $query = "INSERT INTO {$this->table_name} 
                 (position, applicant_id, status, application_date, notes)
                 VALUES (?, ?, 'Pending', CURDATE(), ?)";
        
        $stmt = $this->conn->prepare($query);
        
        $success = $stmt->execute([
            $data['position'],
            $data['applicant_id'],
            $data['notes'] ?? null
        ]);
        
        if ($success) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    public function updateApplication($applicationId, $data) {
        $allowedFields = ['status', 'notes'];
        $updates = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $updates[] = "updated_at = NOW()";
        $params[] = $applicationId;
        
        $query = "UPDATE {$this->table_name} SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        
        return $stmt->execute($params);
    }
    
    public function deleteApplication($applicationId) {
        $query = "UPDATE {$this->table_name} SET status = 'Rejected' WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$applicationId]);
    }
    
    public function logActivity($applicationId, $action, $details) {
        $query = "INSERT INTO recruitment_activity_log 
                 (application_id, action, details, created_at)
                 VALUES (?, ?, ?, NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$applicationId, $action, $details]);
    }
}

// Initialize and process request
$api = new RecruitmentAPI();
$api->processRequest();
?>