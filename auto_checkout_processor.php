<?php
require_once 'db_connect.php';

$database = Database::getInstance();
$pdo = $database->getConnection();

// Process auto-checkouts every minute
$url = $url = "http://localhost/FSM.ESM/attendance_handler.php?action=process_auto_checkouts";
file_get_contents($url);

echo "Auto-checkout processor executed at " . date('Y-m-d H:i:s');
?>