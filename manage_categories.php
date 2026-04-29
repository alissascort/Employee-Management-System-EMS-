<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
session_start();

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Only admin can manage categories
if ($user_type !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? '';

try {
    $db = new Database();
    $pdo = $db->connect();
    
    // Ensure ticket_categories table exists (using existing structure)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    switch ($action) {
        case 'add':
            // Validate required fields
            if (empty($input['name'])) {
                echo json_encode(['success' => false, 'message' => 'Category name is required']);
                exit;
            }
            
            // Check if category name already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM ticket_categories WHERE name = ?");
            $stmt->execute([$input['name']]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Category name already exists']);
                exit;
            }
            
            // Insert new category
            $stmt = $pdo->prepare("INSERT INTO ticket_categories (name, description, is_active) VALUES (?, ?, ?)");
            $stmt->execute([
                $input['name'],
                $input['description'] ?? '',
                $input['status'] === 'inactive' ? 0 : 1
            ]);
            
            $new_category_id = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Category added successfully',
                'category_id' => $new_category_id
            ]);
            break;
            
        case 'edit':
            // Validate required fields
            if (empty($input['category_id']) || empty($input['name'])) {
                echo json_encode(['success' => false, 'message' => 'Category ID and name are required']);
                exit;
            }
            
            $category_id = $input['category_id'];
            
            // Check if category name already exists (excluding current category)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM ticket_categories WHERE name = ? AND id != ?");
            $stmt->execute([$input['name'], $category_id]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Category name already exists']);
                exit;
            }
            
            // Update category
            $stmt = $pdo->prepare("UPDATE ticket_categories SET name = ?, description = ?, is_active = ? WHERE id = ?");
            $stmt->execute([
                $input['name'],
                $input['description'] ?? '',
                $input['status'] === 'inactive' ? 0 : 1,
                $category_id
            ]);
            
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Category not found']);
                exit;
            }
            
            echo json_encode(['success' => true, 'message' => 'Category updated successfully']);
            break;
            
        case 'delete':
            if (empty($input['category_id'])) {
                echo json_encode(['success' => false, 'message' => 'Category ID is required']);
                exit;
            }
            
            $category_id = $input['category_id'];
            
            // Check if category is being used by any tickets
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE category_id = ?");
            $stmt->execute([$category_id]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete category that has associated tickets']);
                exit;
            }
            
            // Delete category
            $stmt = $pdo->prepare("DELETE FROM ticket_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Category not found']);
                exit;
            }
            
            echo json_encode(['success' => true, 'message' => 'Category deleted successfully']);
            break;
            
        case 'get':
            if (empty($input['category_id'])) {
                echo json_encode(['success' => false, 'message' => 'Category ID is required']);
                exit;
            }
            
            $category_id = $input['category_id'];
            
            $stmt = $pdo->prepare("SELECT * FROM ticket_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$category) {
                echo json_encode(['success' => false, 'message' => 'Category not found']);
                exit;
            }
            
            echo json_encode(['success' => true, 'category' => $category]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?> 