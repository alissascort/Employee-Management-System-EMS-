<?php
require_once 'db_connect.php';
if (!isset($_GET['token'])) {
    echo 'Invalid request.';
    exit;
}
$token = $_GET['token'];
try {
    $db = new Database();
    $conn = $db->connect();
    $stmt = $conn->prepare("UPDATE cyber_tips_sent SET read_at = NOW() WHERE token = :token AND read_at IS NULL");
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo '<h2>Thank you for acknowledging this cybersecurity tip!</h2>';
    } else {
        echo '<h2>This tip has already been acknowledged or the link is invalid.</h2>';
    }
} catch (PDOException $e) {
    echo 'Database error.';
} 