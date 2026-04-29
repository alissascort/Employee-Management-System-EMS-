<?php
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();
try {
    $db = new Database();
    $conn = $db->connect();
    
    // Get CSO password hash
    $stmt = $conn->prepare("SELECT password_hash FROM csos WHERE email = ?");
    $stmt->execute(['brightonliston255@gmail.com']);
    $cso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cso) {
        echo "CSO not found\n";
        exit;
    }
    
    $password_hash = $cso['password_hash'];
    echo "Password hash: $password_hash\n";
    
    // Test different passwords
    $test_passwords = [
        'password123',
        'Brighton123!',
        'brighton123',
        '123456',
        'admin',
        'cso123',
        'password',
        'test123'
    ];
    
    foreach ($test_passwords as $password) {
        if (password_verify($password, $password_hash)) {
            echo "✅ Password found: $password\n";
            break;
        } else {
            echo "❌ $password - incorrect\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?> 