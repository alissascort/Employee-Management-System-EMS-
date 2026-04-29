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
    $employee_id = $_GET['employee_id'] ?? null;
    $status = $_GET['status'] ?? null;
    $review_period = $_GET['review_period'] ?? null;
    $limit = $_GET['limit'] ?? 50;
    $offset = $_GET['offset'] ?? 0;

    // Build the base query - FIXED JOIN
    $query = "
        SELECT 
            pr.*,
            sp.firstname,
            sp.lastname,
            sp.employee_code,
            sp.department,
            sp.position,
            CONCAT(sp.firstname, ' ', sp.lastname) as employee_name,
            CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
        FROM performance_reviews pr
        LEFT JOIN staff_profiles sp ON pr.employee_id = sp.employee_code  -- CHANGED: Join on employee_code instead of id
        LEFT JOIN employees u ON pr.created_by = u.employee_id
        WHERE 1=1
    ";

    $params = [];
    $types = '';

    // Add filters if provided
    if ($employee_id) {
        $query .= " AND pr.employee_id = ?";
        $params[] = $employee_id;
        $types .= 's';
    }

    if ($status) {
        $query .= " AND pr.status = ?";
        $params[] = $status;
        $types .= 's';
    }

    if ($review_period) {
        $query .= " AND pr.review_period = ?";
        $params[] = $review_period;
        $types .= 's';
    }

    // Add ordering and pagination
    $query .= " ORDER BY pr.review_date DESC, pr.created_at DESC";
    
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
    
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode JSON metrics field if it exists
    foreach ($reviews as &$review) {
        if (isset($review['metrics']) && $review['metrics']) {
            $review['metrics'] = json_decode($review['metrics'], true);
        }
        
        // Format dates for better display
        if (isset($review['review_date'])) {
            $review['review_date_formatted'] = date('M j, Y', strtotime($review['review_date']));
        }
        if (isset($review['next_review_date'])) {
            $review['next_review_date_formatted'] = date('M j, Y', strtotime($review['next_review_date']));
        }
        
        // Calculate performance level
        if (isset($review['overall_score'])) {
            $review['performance_level'] = getPerformanceLevel($review['overall_score']);
        }
        
        // Ensure employee_name is not empty
        if (empty($review['employee_name']) || trim($review['employee_name']) === ' ') {
            $review['employee_name'] = 'Employee ID: ' . $review['employee_id'];
        }
    }

    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(*) as total 
        FROM performance_reviews pr 
        WHERE 1=1
    ";
    
    $countParams = [];
    $countTypes = '';
    
    if ($employee_id) {
        $countQuery .= " AND pr.employee_id = ?";
        $countParams[] = $employee_id;
    }
    
    if ($status) {
        $countQuery .= " AND pr.status = ?";
        $countParams[] = $status;
    }
    
    if ($review_period) {
        $countQuery .= " AND pr.review_period = ?";
        $countParams[] = $review_period;
    }
    
    $countStmt = $conn->prepare($countQuery);
    
    if (!empty($countParams)) {
        $countStmt->execute($countParams);
    } else {
        $countStmt->execute();
    }
    
    $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $totalCount = $totalResult['total'];

    $response = [
        'success' => true,
        'reviews' => $reviews,
        'total_count' => $totalCount,
        'filters' => [
            'employee_id' => $employee_id,
            'status' => $status,
            'review_period' => $review_period,
            'limit' => $limit,
            'offset' => $offset
        ]
    ];

} catch (PDOException $e) {
    error_log("Database Error in get_performance_reviews.php: " . $e->getMessage());
    $response['message'] = 'Database error occurred: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Error in get_performance_reviews.php: " . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

// Helper function to determine performance level
function getPerformanceLevel($score) {
    if ($score >= 9) return 'Excellent';
    if ($score >= 7) return 'Good';
    if ($score >= 5) return 'Satisfactory';
    if ($score >= 3) return 'Needs Improvement';
    return 'Poor';
}

echo json_encode($response);
?>