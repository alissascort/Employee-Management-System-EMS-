<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();

try {
    // Try system_config table
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_config");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if (count($settings) > 0) {
        echo json_encode([
            'success' => true,
            'config' => [
                'company_name' => $settings['company_name'] ?? 'Fortishield-Matrix',
                'admin_email' => $settings['admin_email'] ?? '',
                'session_timeout' => $settings['session_timeout'] ?? 30,
                'password_min_length' => $settings['password_min_length'] ?? 8,
                'work_start_time' => $settings['work_start_time'] ?? '08:00',
                'work_end_time' => $settings['work_end_time'] ?? '17:00',
                'two_factor_auth' => $settings['two_factor_auth'] ?? '0',
                'maintenance_mode' => $settings['maintenance_mode'] ?? '0'
            ]
        ]);
    } else {
        // Return defaults
        echo json_encode([
            'success' => true,
            'config' => [
                'company_name' => 'Fortishield-Matrix',
                'admin_email' => '',
                'session_timeout' => 30,
                'password_min_length' => 8,
                'work_start_time' => '08:00',
                'work_end_time' => '17:00',
                'two_factor_auth' => '0',
                'maintenance_mode' => '0'
            ]
        ]);
    }
} catch(PDOException $e) {
    // Table might not exist, return defaults
    echo json_encode([
        'success' => true,
        'config' => [
            'company_name' => 'Fortishield-Matrix',
            'admin_email' => '',
            'session_timeout' => 30,
            'password_min_length' => 8,
            'work_start_time' => '08:00',
            'work_end_time' => '17:00',
            'two_factor_auth' => '0',
            'maintenance_mode' => '0'
        ]
    ]);
}
?>
