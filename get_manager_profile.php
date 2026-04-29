<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json");

session_start();
require_once 'db_connect.php';

$response = ['success' => false, 'message' => ''];

try {
    // Debug: Check session status
    error_log("Session ID: " . session_id());
    error_log("Session Data: " . print_r($_SESSION, true));
    
    // If no session manager_id, try to get from POST or check if we can identify the user
    if (!isset($_SESSION['manager_id'])) {
        // Try to get manager by email from POST (if coming from login)
        if (isset($_POST['email'])) {
            $email = $_POST['email'];
            $db = new Database();
            $conn = $db->connect();
            
            $stmt = $conn->prepare("SELECT id, full_name, profile_photo, department FROM dept_managers WHERE email = ?");
            $stmt->execute([$email]);
            $manager = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($manager) {
                $_SESSION['manager_id'] = $manager['id'];
                $_SESSION['manager_email'] = $email;
                $_SESSION['manager_name'] = $manager['full_name'];
                $_SESSION['manager_department'] = $manager['department'];
                
                $response['success'] = true;
                $response['full_name'] = $manager['full_name'];
                $response['profile_photo'] = $manager['profile_photo'];
                $response['department'] = $manager['department'];
            } else {
                $response['message'] = 'Manager not found';
            }
        } else {
            $response['message'] = 'Not logged in';
        }
    } else {
        // We have session data - use it
        $manager_id = $_SESSION['manager_id'];
        $db = new Database();
        $conn = $db->connect();
        
        $stmt = $conn->prepare("SELECT full_name, profile_photo, department FROM dept_managers WHERE id = ?");
        $stmt->execute([$manager_id]);
        $manager = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($manager) {
            $response['success'] = true;
            $response['full_name'] = $manager['full_name'];
            $response['profile_photo'] = $manager['profile_photo'];
            $response['department'] = $manager['department'];
        } else {
            $response['message'] = 'Manager not found in database';
        }
    }
    
} catch (PDOException $e) {
    error_log("Profile Error: " . $e->getMessage());
    $response['message'] = 'Database error occurred';
}

echo json_encode($response);
?>