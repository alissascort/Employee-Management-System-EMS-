<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    // Summary cards
    $totalPayroll = $conn->query("SELECT SUM(net_salary) FROM payroll WHERE pay_period = DATE_FORMAT(NOW(), '%Y-%m')")->fetchColumn();
    $pendingExpenses = $conn->query("SELECT SUM(amount) FROM expenses WHERE status = 'Pending'")->fetchColumn();
    $unpaidInvoices = $conn->query("SELECT SUM(amount) FROM invoices WHERE status = 'Unpaid'")->fetchColumn();
    // Payroll table
    $stmt = $conn->query("SELECT * FROM payroll ORDER BY pay_period DESC LIMIT 10");
    $payroll = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Expenses table
    $stmt = $conn->query("SELECT * FROM expenses ORDER BY date DESC LIMIT 10");
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Invoices table
    $stmt = $conn->query("SELECT * FROM invoices ORDER BY date DESC LIMIT 10");
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Charts (example data, replace with real queries as needed)
    $budgetActual = [
        'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        'budget' => [100000, 105000, 110000, 115000, 120000, 125000],
        'actual' => [95000, 102000, 108000, 112000, 119000, 123000]
    ];
    $expensePie = [
        'labels' => ['Payroll', 'Supplies', 'Travel', 'Utilities', 'Other'],
        'data' => [45, 25, 10, 12, 8]
    ];
    echo json_encode([
        'success' => true,
        'summary' => [
            'totalPayroll' => (float)$totalPayroll,
            'pendingExpenses' => (float)$pendingExpenses,
            'unpaidInvoices' => (float)$unpaidInvoices
        ],
        'payroll' => $payroll,
        'expenses' => $expenses,
        'invoices' => $invoices,
        'charts' => [
            'budgetActual' => $budgetActual,
            'expensePie' => $expensePie
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 