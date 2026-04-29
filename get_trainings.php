<?php
session_start();
header("Content-Type: application/json");

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'hr') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}


require_once 'db_connect.php';

$response = ['success' => false, 'message' => 'An error occurred'];

try {
    $db = new Database();
    $conn = $db->connect();

    // Get optional filters from request
    $status = $_GET['status'] ?? null;
    $type = $_GET['type'] ?? null;
    $trainer = $_GET['trainer'] ?? null;
    $limit = $_GET['limit'] ?? 50;
    $offset = $_GET['offset'] ?? 0;

    // Build the base query
    $query = "
    SELECT 
        t.*,
        CONCAT(u.first_name, ' ', u.last_name) AS creator_name
    FROM trainings t
    LEFT JOIN employees u ON t.trainer_id = u.employee_id
    LEFT JOIN training_participants tp ON t.id = tp.training_id AND tp.status != 'Cancelled'
    WHERE 1=1
";


    $params = [];
    $types = '';

    // Add filters if provided
    if ($status) {
        $query .= " AND t.status = ?";
        $params[] = $status;
        $types .= 's';
    }

    if ($type) {
        $query .= " AND t.type = ?";
        $params[] = $type;
        $types .= 's';
    }

    if ($trainer) {
        $query .= " AND t.trainer_name LIKE ?";
        $params[] = '%' . $trainer . '%';
        $types .= 's';
    }

    // Group by training ID and add ordering
    $query .= " GROUP BY t.id";
    $query .= " ORDER BY t.due_date DESC, t.created_at DESC";
    
    // Add pagination
    if ($limit) {
        $query .= " LIMIT ?";
        $params[] = (int)$limit;
        $types .= 'i';
    }

    if ($offset) {
        $query .= " OFFSET ?";
        $params[] = (int)$offset;
        $types .= 'i';
    }

    $stmt = $conn->prepare($query);
    
    // Bind parameters if any
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    
    $trainings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format dates and add additional calculated fields
    foreach ($trainings as &$training) {
        // Format dates for better display
        if (isset($training['start_date'])) {
            $training['start_date_formatted'] = date('M j, Y g:i A', strtotime($training['start_date']));
            $training['start_date_short'] = date('M j, Y', strtotime($training['start_date']));
        }
        
        if (isset($training['end_date'])) {
            $training['end_date_formatted'] = date('M j, Y g:i A', strtotime($training['end_date']));
            $training['end_date_short'] = date('M j, Y', strtotime($training['end_date']));
        }
        
        if (isset($training['created_at'])) {
            $training['created_at_formatted'] = date('M j, Y', strtotime($training['created_at']));
        }
        
        // Calculate days until training
        if (isset($training['start_date'])) {
            $startDate = new DateTime($training['start_date']);
            $now = new DateTime();
            $interval = $now->diff($startDate);
            $training['days_until'] = $interval->days;
            $training['is_upcoming'] = $startDate > $now;
            $training['is_ongoing'] = $startDate <= $now && new DateTime($training['end_date']) >= $now;
            $training['is_past'] = new DateTime($training['end_date']) < $now;
        }
        
        // Calculate registration progress
        if (isset($training['max_participants']) && $training['max_participants'] > 0) {
            $training['registration_progress'] = round(($training['current_participants'] / $training['max_participants']) * 100);
            $training['available_slots'] = $training['max_participants'] - $training['current_participants'];
        } else {
            $training['registration_progress'] = 0;
            $training['available_slots'] = 0;
        }
        
        // Add status badge class
        $training['status_badge_class'] = getTrainingStatusBadgeClass($training['status']);
        
        // Format cost if exists
        if (isset($training['cost_per_participant'])) {
            $training['cost_per_participant_formatted'] = '$' . number_format($training['cost_per_participant'], 2);
        }
        
        if (isset($training['total_budget'])) {
            $training['total_budget_formatted'] = '$' . number_format($training['total_budget'], 2);
        }
    }

    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(DISTINCT t.id) as total 
        FROM trainings t 
        WHERE 1=1
    ";
    
    $countParams = [];
    
    if ($status) {
        $countQuery .= " AND t.status = ?";
        $countParams[] = $status;
    }
    
    if ($type) {
        $countQuery .= " AND t.type = ?";
        $countParams[] = $type;
    }
    
    if ($trainer) {
        $countQuery .= " AND t.trainer_name LIKE ?";
        $countParams[] = '%' . $trainer . '%';
    }
    
    $countStmt = $conn->prepare($countQuery);
    
    if (!empty($countParams)) {
        $countStmt->execute($countParams);
    } else {
        $countStmt->execute();
    }
    
    $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $totalCount = $totalResult['total'];

    // Get training statistics
    $statsQuery = "
        SELECT 
            COUNT(*) as total_trainings,
            SUM(CASE WHEN status = 'Scheduled' THEN 1 ELSE 0 END) as scheduled,
            SUM(CASE WHEN status = 'Open for Registration' THEN 1 ELSE 0 END) as open_registration,
            SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(max_participants) as total_capacity,
            SUM(tp.participant_count) as total_participants
        FROM trainings t
        LEFT JOIN (
            SELECT training_id, COUNT(*) as participant_count 
            FROM training_participants 
            WHERE status != 'Cancelled' 
            GROUP BY training_id
        ) tp ON t.id = tp.training_id
    ";
    
    $statsStmt = $conn->prepare($statsQuery);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $response = [
        'success' => true,
        'trainings' => $trainings,
        'total_count' => $totalCount,
        'statistics' => $stats,
        'filters' => [
            'status' => $status,
            'type' => $type,
            'trainer' => $trainer,
            'limit' => $limit,
            'offset' => $offset
        ]
    ];

} catch (PDOException $e) {
    error_log("Database Error in get_trainings.php: " . $e->getMessage());
    $response['message'] = 'Database error occurred: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Error in get_trainings.php: " . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

// Helper function to determine training status badge class
function getTrainingStatusBadgeClass($status) {
    $statusMap = [
        'Draft' => 'bg-secondary',
        'Scheduled' => 'bg-info',
        'Open for Registration' => 'bg-success',
        'In Progress' => 'bg-warning',
        'Completed' => 'bg-primary',
        'Cancelled' => 'bg-danger'
    ];
    
    return $statusMap[$status] ?? 'bg-secondary';
}

echo json_encode($response);
?>
