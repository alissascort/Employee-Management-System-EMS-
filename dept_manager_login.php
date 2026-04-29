<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json");
require_once 'db_connect.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $response['message'] = 'Email and password are required';
        echo json_encode($response);
        exit;
    }
    
    try {
        $db = new Database();
        $conn = $db->connect();
        
        // Check if department manager exists
        $stmt = $conn->prepare("SELECT * FROM dept_managers WHERE email = ?");
        $stmt->execute([$email]);
        $manager = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$manager) {
            $response['message'] = 'No manager found with this email address';
            echo json_encode($response);
            exit;
        }
        
        // Verify password
        if (password_verify($password, $manager['password_hash'])) {
            $response['success'] = true;
            $response['message'] = 'Login successful';
            $response['manager'] = [
                'id' => $manager['id'],
                'full_name' => $manager['full_name'],
                'email' => $manager['email'],
                'department' => $manager['department'],
                'dm_code' => $manager['dm_code']
            ];
            
            // Start session and store manager data
            session_start();
            $_SESSION['manager_id'] = $manager['id'];
            $_SESSION['manager_email'] = $manager['email'];
            $_SESSION['manager_name'] = $manager['full_name'];
            $_SESSION['manager_department'] = $manager['department'];
            $_SESSION['manager_role'] = 'dept_manager';
            
        } else {
            $response['message'] = 'Incorrect password';
        }
        
    } catch (PDOException $e) {
        error_log("Login Error: " . $e->getMessage());
        $response['message'] = 'Database error occurred';
    }
} else {
    $response['message'] = 'Invalid request method';
}

echo json_encode($response);
?>