<?php
/**
 * get_scan_progress.php
 * Returns real-time scan progress from the scan_sessions table.
 */
header('Content-Type: application/json');
require_once 'db_connect.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'cso') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $db   = new Database();
    $conn = $db->connect();

    $stmt = $conn->query("SELECT status, progress, started_at, completed_at 
                          FROM scan_sessions 
                          ORDER BY started_at DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => true, 'status' => 'IDLE', 'progress' => 0]);
        exit;
    }

    echo json_encode([
        'success'      => true,
        'status'       => strtoupper($row['status']),
        'progress'     => (int)$row['progress'],
        'started_at'   => $row['started_at'],
        'completed_at' => $row['completed_at']
    ]);

} catch (Exception $e) {
    // Table doesn't exist yet — safe fallback
    echo json_encode(['success' => true, 'status' => 'IDLE', 'progress' => 0]);
}
