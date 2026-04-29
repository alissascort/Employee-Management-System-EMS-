<?php
header('Content-Type: application/json');

// Database connection
$db = new PDO('mysql:host=localhost;dbname=employee_management_system', 'ems_user', 'securepassword123');

// Get performance metrics
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $system = $_GET['system'] ?? 'all';
    $range = $_GET['range'] ?? 30;
    
    $endDate = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime("-$range days"));
    
    $query = "SELECT date, cpu_usage, memory_usage, response_time FROM performance_metrics 
              WHERE date BETWEEN ? AND ?";
    $params = [$startDate, $endDate];
    
    if ($system !== 'all') {
        $query .= " AND system = ?";
        $params[] = $system;
    }
    
    $query .= " ORDER BY date ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare data for charts
    $result = [
        'labels' => array_column($metrics, 'date'),
        'cpu' => array_column($metrics, 'cpu_usage'),
        'memory' => array_column($metrics, 'memory_usage'),
        'response' => array_column($metrics, 'response_time')
    ];
    
    echo json_encode(['success' => true, 'metrics' => $result]);
}

// Export report
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once('tcpdf/tcpdf.php');
    
    // Generate PDF report
    // ...
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="performance_report.pdf"');
    echo $pdfContent;
    exit;
}
?>
