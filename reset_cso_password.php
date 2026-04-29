<?php
require_once 'db_connect.php';

try {
    $db = new Database();
    $conn = $db->connect();
    
    $new_password = 'cso123456';
    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("UPDATE csos SET password_hash = ? WHERE email = ?");
    $stmt->execute([$password_hash, 'brightonliston255@gmail.com']);
    
    if ($stmt->rowCount() > 0) {
        echo "✅ CSO password reset successfully!\n";
        echo "New password: $new_password\n";
        echo "New hash: $password_hash\n";
    } else {
        echo "❌ Failed to reset password\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?> 