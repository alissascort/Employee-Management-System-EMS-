<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    http_response_code(403);
    exit;
}

header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="terminal_logs_' . date('Y-m-d') . '.txt"');

// Generate log file content
$content = "Fortishield-Matrix Terminal Logs\n";
$content .= "Generated: " . date('Y-m-d H:i:s') . "\n";
$content .= "User: " . ($_SESSION['user_name'] ?? 'Unknown') . "\n";
$content .= "========================================\n\n";

// Add recent logs (you would fetch from database in production)
if (file_exists('terminal_audit.log')) {
    $logs = file('terminal_audit.log', FILE_IGNORE_NEW_LINES);
    $recentLogs = array_slice($logs, -50); // Last 50 entries
    $content .= implode("\n", $recentLogs);
}

echo $content;
?>