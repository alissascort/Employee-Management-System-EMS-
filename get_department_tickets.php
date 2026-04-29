<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

try {
    $db = new Database();
    $pdo = $db->connect();
    
    $tickets = [];
    
    // Check if user has department access (admin, dept_manager, or employee with department)
    if ($user_type === 'admin') {
        // Admin can see all tickets
        $stmt = $pdo->query('SELECT t.*, e.first_name, e.last_name, e.employee_code FROM tickets t LEFT JOIN employees e ON t.created_by = e.employee_id ORDER BY t.created_at DESC');
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($user_type === 'dept_manager') {
        // Department manager can see tickets from their department
        $stmt = $pdo->prepare("SELECT department FROM dept_managers WHERE manager_id = ?");
        $stmt->execute([$user_id]);
        $manager = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($manager && $manager['department']) {
            $stmt = $pdo->prepare("SELECT t.*, e.first_name, e.last_name, e.employee_code FROM tickets t LEFT JOIN employees e ON t.created_by = e.employee_id WHERE t.department = ? ORDER BY t.created_at DESC");
            $stmt->execute([$manager['department']]);
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($user_type === 'employee') {
        // Employee can see tickets from their department
        $stmt = $pdo->prepare("SELECT department FROM employees WHERE employee_id = ?");
        $stmt->execute([$user_id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($employee && $employee['department']) {
            $stmt = $pdo->prepare("SELECT t.*, e.first_name, e.last_name, e.employee_code FROM tickets t LEFT JOIN employees e ON t.created_by = e.employee_id WHERE t.department = ? ORDER BY t.created_at DESC");
            $stmt->execute([$employee['department']]);
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        // Other user types (CSO, HR) can see tickets assigned to them
        $stmt = $pdo->prepare("SELECT t.*, e.first_name, e.last_name, e.employee_code FROM tickets t LEFT JOIN employees e ON t.created_by = e.employee_id WHERE t.assigned_role = ? ORDER BY t.created_at DESC");
        $stmt->execute([$user_type]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Format the tickets data
    $formattedTickets = [];
    foreach ($tickets as $ticket) {
        $formattedTickets[] = [
            'id' => $ticket['id'],
            'title' => $ticket['title'],
            'description' => $ticket['description'],
            'status' => $ticket['status'],
            'priority' => $ticket['priority'],
            'department' => $ticket['department'],
            'created_by' => $ticket['created_by'],
            'created_by_name' => $ticket['first_name'] && $ticket['last_name'] ? $ticket['first_name'] . ' ' . $ticket['last_name'] : 'Unknown',
            'created_by_code' => $ticket['employee_code'] ?? 'N/A',
            'assigned_to' => $ticket['assigned_to'],
            'assigned_role' => $ticket['assigned_role'],
            'created_at' => $ticket['created_at'],
            'updated_at' => $ticket['updated_at']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'tickets' => $formattedTickets,
        'user_type' => $user_type,
        'total_count' => count($formattedTickets)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?> 