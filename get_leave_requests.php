<?php
header("Content-Type: application/json");
require_once 'db_connect.php';

// Set default pagination values
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 6; // Items per page
$offset = max(0, ($page - 1) * $perPage); // Ensure offset is never negative

// Additional filters
$statusFilter = isset($_GET['status']) && in_array($_GET['status'], ['PENDING', 'APPROVED', 'REJECTED']) 
    ? $_GET['status'] 
    : null;

$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : null;

try {
    $db = new Database();
    $conn = $db->connect();

    // Base query for counting and fetching
    $baseQuery = "FROM leave_requests";

    // WHERE conditions
    $conditions = [];
    $params = [];
    
    if ($statusFilter) {
        $conditions[] = "status = :status";
        $params[':status'] = $statusFilter;
    }
    
    if ($searchQuery) {
        $conditions[] = "(employee_name LIKE :search OR reason LIKE :search OR department LIKE :search)";
        $params[':search'] = "%$searchQuery%";
    }
    
    $whereClause = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";

    // Get total count with filters
    $countQuery = "SELECT COUNT(*) $baseQuery $whereClause";
    $countStmt = $conn->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRows = $countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage)); // Ensure at least 1 page
    
    // Adjust page if out of bounds
    $page = max(1, min($page, $totalPages));

    // Get paginated results
    $fetchQuery = "SELECT * $baseQuery $whereClause ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($fetchQuery);
    
    // Bind all parameters
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $leaveRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format dates for better display
    foreach ($leaveRequests as &$request) {
        $request['formatted_start_date'] = date('d M Y', strtotime($request['start_date']));
        $request['formatted_end_date'] = date('d M Y', strtotime($request['end_date']));
        $request['formatted_created_at'] = date('d M Y H:i', strtotime($request['created_at']));
        $request['days'] = round((strtotime($request['end_date']) - strtotime($request['start_date'])) / (60 * 60 * 24)) + 1;
    }

    echo json_encode([
        'success' => true,
        'requests' => $leaveRequests,
        'page' => $page,
        'totalPages' => $totalPages,
        'totalItems' => $totalRows,
        'itemsPerPage' => $perPage,
        'hasNextPage' => $page < $totalPages,
        'hasPrevPage' => $page > 1
    ]);

} catch (PDOException $e) {
    // Log the error for debugging
    error_log("Database error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to retrieve leave requests. Please try again later.',
        'error' => $e->getMessage() // Only include in development
    ]);
}
?>
