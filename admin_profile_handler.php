<?php
session_start();
header("Content-Type: application/json");

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';

$response = ['success' => false, 'message' => 'An error occurred'];

try {
    $db = new Database();
    $conn = $db->connect();
    
    $admin_id = $_SESSION['user_id'];
    $full_name = $_POST['full_name'] ?? '';
    
    // Validate full name
    if (empty($full_name) || strlen($full_name) < 2) {
        $response['message'] = 'Full name must be at least 2 characters';
        echo json_encode($response);
        exit;
    }
    
    // Handle profile photo upload
    $profile_photo_path = null;
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        $file_type = mime_content_type($_FILES['profile_photo']['tmp_name']);
        $file_size = $_FILES['profile_photo']['size'];
        
        if (!in_array($file_type, $allowed_types) || $file_size > $max_size) {
            $response['message'] = 'Invalid image type or size (JPG, PNG, max 2MB)';
            echo json_encode($response);
            exit;
        }
        
        $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $file_name = 'admin_profile_' . $admin_id . '_' . uniqid() . '.' . $ext;
        $upload_dir = __DIR__ . '/uploads/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $target_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $target_path)) {
            $profile_photo_path = 'uploads/' . $file_name;
        } else {
            $response['message'] = 'Failed to upload profile photo';
            echo json_encode($response);
            exit;
        }
    }
    
    // Update admin profile in database
    if ($profile_photo_path) {
        $stmt = $conn->prepare("UPDATE admins SET full_name = ?, profile_photo = ? WHERE admin_id = ?");
        $stmt->execute([$full_name, $profile_photo_path, $admin_id]);
    } else {
        $stmt = $conn->prepare("UPDATE admins SET full_name = ? WHERE admin_id = ?");
        $stmt->execute([$full_name, $admin_id]);
    }
    
    if ($stmt->rowCount() > 0) {
        $response['success'] = true;
        $response['message'] = 'Profile updated successfully';
        if ($profile_photo_path) {
            $response['profile_photo'] = $profile_photo_path;
        }
    } else {
        $response['message'] = 'No changes made';
    }
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $response['message'] = 'Database error occurred';
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $response['message'] = 'An error occurred';
}

echo json_encode($response);
?> 