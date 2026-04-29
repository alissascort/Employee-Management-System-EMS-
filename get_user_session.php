<?php
header('Content-Type: application/json');
session_start();

// Check if user is authenticated and is HR
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'hr') {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once 'db_connect.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Get HR user details including profile photo
    $stmt = $conn->prepare("SELECT full_name, profile_photo FROM hr WHERE hr_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $hrDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $fullName = $hrDetails['full_name'] ?? $_SESSION['full_name'] ?? '';
    $nameParts = explode(' ', trim($fullName), 2);
    $firstName = $nameParts[0] ?? '';
    $lastName = $nameParts[1] ?? '';
    
    // Return user session data with profile photo
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $_SESSION['user_id'],
            'email' => $_SESSION['email'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'profile_photo' => $hrDetails['profile_photo'] ?? null,
            'role' => $_SESSION['user_type']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error retrieving HR user details: " . $e->getMessage());
    
    // Fallback to session data only
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $_SESSION['user_id'],
            'email' => $_SESSION['email'],
            'first_name' => isset($_SESSION['full_name']) ? explode(' ', $_SESSION['full_name'])[0] : '',
            'last_name' => isset($_SESSION['full_name']) ? (explode(' ', $_SESSION['full_name'])[1] ?? '') : '',
            'full_name' => $_SESSION['full_name'] ?? '',
            'profile_photo' => null,
            'role' => $_SESSION['user_type']
        ]
    ]);
}
?>
