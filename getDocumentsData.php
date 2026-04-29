<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';

class DocumentManager {
    private $conn;
    private $table_name = "hr_documents";
    private $upload_dir = "../uploads/documents/";
    
    public function __construct($db) {
        $this->conn = $db;
        
        // Create upload directory if it doesn't exist
        if (!file_exists($this->upload_dir)) {
            mkdir($this->upload_dir, 0777, true);
        }
    }
    
    public function getDocuments($filters = []) {
        try {
            $whereConditions = [];
            $params = [];
            
            // Build filter conditions
            if (!empty($filters['type'])) {
                $whereConditions[] = "document_type = ?";
                $params[] = $filters['type'];
            }
            
            if (!empty($filters['department'])) {
                $whereConditions[] = "department_id = ?";
                $params[] = $filters['department'];
            }
            
            if (!empty($filters['search'])) {
                $whereConditions[] = "(title LIKE ? OR description LIKE ?)";
                $searchTerm = "%{$filters['search']}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (!empty($filters['date_from'])) {
                $whereConditions[] = "upload_date >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = "upload_date <= ?";
                $params[] = $filters['date_to'];
            }
            
            $whereClause = "";
            if (!empty($whereConditions)) {
                $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            }
            
            // CORRECTED QUERY - Using actual column names from your tables
            $query = "SELECT 
                        d.id,
                        d.title,
                        d.description,
                        d.document_type,
                        d.file_name,
                        d.file_path,
                        d.file_size,
                        d.file_type,
                        d.department_id,
                        d.uploaded_by,
                        d.upload_date,
                        d.expiry_date,
                        d.status,
                        d.access_level,
                        d.download_count,
                        d.last_accessed,
                        dep.name as department_name,  -- CHANGED: dep.name instead of dep.department_name
                        u.full_name as uploaded_by_name
                    FROM {$this->table_name} d
                    LEFT JOIN departments dep ON d.department_id = dep.department_id
                    LEFT JOIN users u ON d.uploaded_by = u.user_id
                    {$whereClause}
                    ORDER BY d.upload_date DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format file sizes and check expiry
            foreach ($documents as &$doc) {
                $doc['file_size_formatted'] = $this->formatFileSize($doc['file_size']);
                $doc['is_expired'] = $doc['expiry_date'] && strtotime($doc['expiry_date']) < time();
            }
            
            // Get document statistics
            $stats = $this->getDocumentStatistics();
            
            return [
                'success' => true,
                'data' => $documents,
                'statistics' => $stats,
                'total_count' => count($documents)
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function uploadDocument($file, $documentData) {
        try {
            // Validate file
            if (!$this->validateFile($file)) {
                throw new Exception("File validation failed");
            }
            
            // Generate unique filename
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $documentData['title']) . '.' . $fileExtension;
            $filePath = $this->upload_dir . $fileName;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new Exception("Failed to move uploaded file");
            }
            
            // Using all available columns
            $query = "INSERT INTO {$this->table_name} 
                     (title, description, document_type, file_name, file_path, file_size, file_type, 
                      department_id, uploaded_by, upload_date, expiry_date, access_level)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $success = $stmt->execute([
                $documentData['title'],
                $documentData['description'] ?? '',
                $documentData['document_type'],
                $fileName,
                $filePath,
                $file['size'],
                $file['type'],
                $documentData['department_id'] ?? null,
                $documentData['uploaded_by'],
                $documentData['expiry_date'] ?? null,
                $documentData['access_level'] ?? 'private'
            ]);
            
            if ($success) {
                $documentId = $this->conn->lastInsertId();
                
                // Log document activity
                $this->logDocumentActivity($documentId, 'UPLOADED', 'Document uploaded');
                
                return [
                    'success' => true,
                    'message' => 'Document uploaded successfully',
                    'document_id' => $documentId,
                    'file_path' => $filePath
                ];
            } else {
                // Clean up file if database insert failed
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                throw new Exception("Failed to save document record");
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function downloadDocument($documentId, $userId) {
        try {
            $query = "SELECT * FROM {$this->table_name} WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$documentId]);
            $document = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$document) {
                throw new Exception("Document not found");
            }
            
            // Check access permissions
            if (!$this->checkDocumentAccess($document, $userId)) {
                throw new Exception("Access denied");
            }
            
            $filePath = $document['file_path'];
            
            if (!file_exists($filePath)) {
                throw new Exception("File not found on server");
            }
            
            // Update download count and last accessed
            $this->updateDocumentAccess($documentId);
            
            // Log download activity
            $this->logDocumentActivity($documentId, 'DOWNLOADED', 'Document downloaded by user');
            
            // Set headers for download
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $document['file_type']);
            header('Content-Disposition: attachment; filename="' . $document['file_name'] . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            
            readfile($filePath);
            exit;
            
        } catch (Exception $e) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    public function deleteDocument($documentId) {
        try {
            $query = "SELECT file_path FROM {$this->table_name} WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$documentId]);
            $document = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$document) {
                throw new Exception("Document not found");
            }
            
            $deleteQuery = "DELETE FROM {$this->table_name} WHERE id = ?";
            $stmt = $this->conn->prepare($deleteQuery);
            $success = $stmt->execute([$documentId]);
            
            if ($success) {
                // Delete physical file
                if (file_exists($document['file_path'])) {
                    unlink($document['file_path']);
                }
                
                // Log deletion activity
                $this->logDocumentActivity($documentId, 'DELETED', 'Document permanently deleted');
                
                return ['success' => true, 'message' => 'Document deleted successfully'];
            } else {
                throw new Exception("Failed to delete document record");
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function validateFile($file) {
        $allowedTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'image/jpeg',
            'image/png'
        ];
        
        $maxFileSize = 50 * 1024 * 1024; // 50MB
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload error: " . $file['error']);
        }
        
        if ($file['size'] > $maxFileSize) {
            throw new Exception("File size exceeds maximum limit of 50MB");
        }
        
        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception("File type not allowed");
        }
        
        return true;
    }
    
    // Full access control functionality
    private function checkDocumentAccess($document, $userId) {
        // Implement access control logic based on department, role, etc.
        
        if ($document['access_level'] === 'public') {
            return true;
        }
        
        // Check if user has access to the document's department
        // Using staff_profiles table with employee_id
        $query = "SELECT department FROM staff_profiles WHERE employee_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Compare department names
        return $user && $user['department'] == $document['department_name'];
    }
    
    // Track downloads and access
    private function updateDocumentAccess($documentId) {
        $query = "UPDATE {$this->table_name} 
                 SET download_count = download_count + 1, last_accessed = NOW() 
                 WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$documentId]);
    }
    
    private function getDocumentStatistics() {
        // Total documents by type
        $typeQuery = "SELECT document_type, COUNT(*) as count 
                     FROM {$this->table_name} 
                     WHERE status = 'active'
                     GROUP BY document_type";
        $stmt = $this->conn->prepare($typeQuery);
        $stmt->execute();
        $typeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Storage usage
        $storageQuery = "SELECT SUM(file_size) as total_size FROM {$this->table_name} WHERE status = 'active'";
        $stmt = $this->conn->prepare($storageQuery);
        $stmt->execute();
        $storage = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Recent activity
        $activityQuery = "SELECT COUNT(*) as recent_uploads 
                         FROM {$this->table_name} 
                         WHERE upload_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'active'";
        $stmt = $this->conn->prepare($activityQuery);
        $stmt->execute();
        $recentActivity = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Expired documents count
        $expiredQuery = "SELECT COUNT(*) as expired_count 
                        FROM {$this->table_name} 
                        WHERE expiry_date < NOW() AND status = 'active'";
        $stmt = $this->conn->prepare($expiredQuery);
        $stmt->execute();
        $expired = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'type_distribution' => $typeStats,
            'total_storage' => $storage['total_size'] ?? 0,
            'recent_uploads' => $recentActivity['recent_uploads'] ?? 0,
            'expired_documents' => $expired['expired_count'] ?? 0
        ];
    }
    
    private function logDocumentActivity($documentId, $action, $details) {
        try {
            $query = "INSERT INTO document_activity_log 
                     (document_id, action, details, created_at)
                     VALUES (?, ?, ?, NOW())";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$documentId, $action, $details]);
        } catch (Exception $e) {
            // If table doesn't exist, just log to error log
            error_log("Document Activity: " . $action . " - " . $details);
        }
    }
    
    private function formatFileSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// Process requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $database = new Database();
    $db = $database->getConnection();
    $documentManager = new DocumentManager($db);
    
    $filters = [
        'type' => $_GET['type'] ?? null,
        'department' => $_GET['department'] ?? null,
        'search' => $_GET['search'] ?? null,
        'date_from' => $_GET['date_from'] ?? null,
        'date_to' => $_GET['date_to'] ?? null
    ];
    
    $result = $documentManager->getDocuments($filters);
    echo json_encode($result);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['document'])) {
        $database = new Database();
        $db = $database->getConnection();
        $documentManager = new DocumentManager($db);
        
        $documentData = [
            'title' => $_POST['title'],
            'description' => $_POST['description'] ?? '',
            'document_type' => $_POST['document_type'],
            'department_id' => $_POST['department_id'] ?? null,
            'uploaded_by' => $_POST['uploaded_by'],
            'expiry_date' => $_POST['expiry_date'] ?? null,
            'access_level' => $_POST['access_level'] ?? 'private'
        ];
        
        $result = $documentManager->uploadDocument($_FILES['document'], $documentData);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    }
}
?>