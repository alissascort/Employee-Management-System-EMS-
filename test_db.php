<?php
require_once 'db_connect.php';

$db = new Database();
$conn = $db->connect();

if ($conn) {
    echo "Database connection successful!";
    // Test query
    $stmt = $conn->query("SHOW TABLES");
    echo "<pre>Tables: " . print_r($stmt->fetchAll(), true) . "</pre>";
} else {
    echo "Connection failed - check error logs";
}