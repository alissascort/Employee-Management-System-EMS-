<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->connect();
    
    // Check if ticket_categories table exists, if not create it with default categories
    $stmt = $pdo->query("SHOW TABLES LIKE 'ticket_categories'");
    if ($stmt->rowCount() == 0) {
        // Create the table
        $pdo->exec("CREATE TABLE ticket_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Insert default categories
        $defaultCategories = [
            ['name' => 'IT Issue', 'description' => 'Technical problems and system issues'],
            ['name' => 'IT Request', 'description' => 'Software and hardware requests'],
            ['name' => 'IT Equipment', 'description' => 'Equipment maintenance and replacement'],
            ['name' => 'HR Query', 'description' => 'Human resources related questions'],
            ['name' => 'Facility Request', 'description' => 'Building and facility maintenance'],
            ['name' => 'Security Issue', 'description' => 'Security concerns and access requests'],
            ['name' => 'General Inquiry', 'description' => 'General questions and information requests']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO ticket_categories (name, description) VALUES (?, ?)");
        foreach ($defaultCategories as $category) {
            $stmt->execute([$category['name'], $category['description']]);
        }
    }
    
    // Fetch all categories (for management) or only active categories (for ticket creation)
    $fetchAll = $_GET['all'] ?? false;
    if ($fetchAll) {
        $stmt = $pdo->prepare("SELECT id, name, description, is_active, created_at FROM ticket_categories ORDER BY name");
    } else {
        $stmt = $pdo->prepare("SELECT id, name, description FROM ticket_categories WHERE is_active = TRUE ORDER BY name");
    }
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching categories: ' . $e->getMessage()
    ]);
}
?> 