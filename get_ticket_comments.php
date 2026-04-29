<?php
header('Content-Type: application/json');
session_start();
require_once 'db_connect.php';

try {
    $db = new Database();
    $pdo = $db->connect();
    
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }
    
    // Get ticket ID from request
    $ticketId = $_GET['ticket_id'] ?? null;
    
    if (!$ticketId) {
        echo json_encode(['success' => false, 'message' => 'Ticket ID is required']);
        exit;
    }
    
    // Ensure ticket_comments table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        user_id INT NOT NULL,
        user_name VARCHAR(255) NOT NULL,
        user_role VARCHAR(50) NOT NULL,
        comment TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
    )");
    
    // Fetch comments for the specific ticket
    $stmt = $pdo->prepare("
        SELECT tc.*, 
               CASE 
                   WHEN e.first_name IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                   WHEN a.full_name IS NOT NULL THEN a.full_name
                   WHEN dm.full_name IS NOT NULL THEN dm.full_name
                   WHEN h.full_name IS NOT NULL THEN h.full_name
                   WHEN c.full_name IS NOT NULL THEN c.full_name
                   ELSE 'Unknown User'
               END as display_name
        FROM ticket_comments tc
        LEFT JOIN employees e ON tc.user_id = e.employee_id AND tc.user_role = 'employee'
        LEFT JOIN admins a ON tc.user_id = a.admin_id AND tc.user_role = 'admin'
        LEFT JOIN dept_managers dm ON tc.user_id = dm.id AND tc.user_role = 'dept_manager'
        LEFT JOIN hr h ON tc.user_id = h.hr_id AND tc.user_role = 'hr'
        LEFT JOIN csos c ON tc.user_id = c.cso_id AND tc.user_role = 'cso'
        WHERE tc.ticket_id = ?
        ORDER BY tc.created_at ASC
    ");
    
    $stmt->execute([$ticketId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format comments for frontend
    $formattedComments = [];
    foreach ($comments as $comment) {
        $formattedComments[] = [
            'id' => $comment['id'],
            'ticket_id' => $comment['ticket_id'],
            'user_id' => $comment['user_id'],
            'user_name' => $comment['display_name'],
            'user_role' => $comment['user_role'],
            'comment' => $comment['comment'],
            'created_at' => $comment['created_at']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'comments' => $formattedComments,
        'total_count' => count($formattedComments)
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching ticket comments: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch comments: ' . $e->getMessage()
    ]);
}
?>