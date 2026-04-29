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
    $pendingRequests = $conn->query("SELECT COUNT(*) FROM procurement_requests WHERE status = 'Pending'")->fetchColumn();
    $activeVendors = $conn->query("SELECT COUNT(*) FROM vendors WHERE status = 'Active'")->fetchColumn();
    $openPOs = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Open'")->fetchColumn();
    $budgetUsed = $conn->query("SELECT SUM(amount) FROM procurement_requests WHERE status IN ('Approved','Completed')")->fetchColumn();
    $budgetTotal = $conn->query("SELECT SUM(budget) FROM departments")->fetchColumn();
    $budgetPercent = $budgetTotal > 0 ? round(($budgetUsed / $budgetTotal) * 100) : 0;
    // Recent requests
    $stmt = $conn->query("SELECT * FROM procurement_requests ORDER BY created_at DESC LIMIT 5");
    $recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Vendors
    $stmt = $conn->query("SELECT * FROM vendors ORDER BY name ASC LIMIT 10");
    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Orders
    $stmt = $conn->query("SELECT * FROM purchase_orders ORDER BY created_at DESC LIMIT 10");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Inventory
    $stmt = $conn->query("SELECT * FROM inventory ORDER BY item_code ASC LIMIT 10");
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Charts (example data, replace with real queries as needed)
    $vendorPerf = [
        'labels' => ['Tech Solutions', 'Office Supplies+', 'Security Pro', 'Furniture World', 'Training Experts'],
        'data' => [3, 5, 2, 7, 4]
    ];
    $category = [
        'labels' => ['IT Equipment', 'Office Supplies', 'Security', 'Furniture', 'Services'],
        'data' => [35, 20, 25, 15, 5]
    ];
    $deptSpend = [
        'labels' => ['IT', 'Security', 'Operations', 'HR', 'Finance'],
        'data' => [12500, 8700, 6500, 3200, 2800]
    ];
    $categorySpend = [
        'labels' => ['IT Equipment', 'Office Supplies', 'Security', 'Furniture', 'Services'],
        'data' => [12500, 4500, 8700, 3200, 1500]
    ];
    echo json_encode([
        'success' => true,
        'summary' => [
            'pendingRequests' => (int)$pendingRequests,
            'activeVendors' => (int)$activeVendors,
            'openPOs' => (int)$openPOs,
            'budgetPercent' => (int)$budgetPercent
        ],
        'recentRequests' => $recentRequests,
        'vendors' => $vendors,
        'orders' => $orders,
        'inventory' => $inventory,
        'charts' => [
            'vendorPerf' => $vendorPerf,
            'category' => $category,
            'deptSpend' => $deptSpend,
            'categorySpend' => $categorySpend
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 