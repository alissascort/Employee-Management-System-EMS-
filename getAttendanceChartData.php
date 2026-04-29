<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

class AttendanceChartManager {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function getAttendanceChartData($period = 'week', $department = null) {
        try {
            $data = [];
            
            switch ($period) {
                case 'week':
                    $data = $this->getWeeklyAttendance($department);
                    break;
                case 'month':
                    $data = $this->getMonthlyAttendance($department);
                    break;
                case 'quarter':
                    $data = $this->getQuarterlyAttendance($department);
                    break;
                case 'year':
                    $data = $this->getYearlyAttendance($department);
                    break;
                default:
                    $data = $this->getWeeklyAttendance($department);
            }
            
            // Add summary statistics
            $data['summary'] = $this->getAttendanceSummary($department);
            $data['trends'] = $this->getAttendanceTrends($department);
            $data['department_comparison'] = $this->getDepartmentComparison();
            
            return [
                'success' => true,
                'data' => $data
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function getWeeklyAttendance($department = null) {
        $whereConditions = ["a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"];
        $params = [];
        
        if ($department) {
            $whereConditions[] = "e.department_id = ?";
            $params[] = $department;
        }
        
        $whereClause = "WHERE " . implode(" AND ", $whereConditions);
        
        $query = "SELECT 
                    DATE(a.attendance_date) as date,
                    DAYNAME(a.attendance_date) as day_name,
                    COUNT(DISTINCT a.employee_id) as total_employees,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) as half_day_count,
                    AVG(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) * 100 as attendance_rate,
                    AVG(a.working_hours) as avg_working_hours,
                    AVG(a.overtime_hours) as avg_overtime_hours
                FROM attendance a
                JOIN employees e ON a.employee_id = e.employee_id
                {$whereClause}
                GROUP BY DATE(a.attendance_date), DAYNAME(a.attendance_date)
                ORDER BY a.attendance_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $weeklyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format for chart.js
        $chartData = [
            'labels' => [],
            'datasets' => [
                'present' => [],
                'late' => [],
                'absent' => [],
                'attendance_rate' => []
            ]
        ];
        
        foreach ($weeklyData as $day) {
            $chartData['labels'][] = date('D M j', strtotime($day['date']));
            $chartData['datasets']['present'][] = $day['present_count'];
            $chartData['datasets']['late'][] = $day['late_count'];
            $chartData['datasets']['absent'][] = $day['absent_count'];
            $chartData['datasets']['attendance_rate'][] = round($day['attendance_rate'], 1);
        }
        
        return [
            'weekly_data' => $weeklyData,
            'chart_data' => $chartData
        ];
    }
    
    private function getMonthlyAttendance($department = null) {
        $whereConditions = ["a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"];
        $params = [];
        
        if ($department) {
            $whereConditions[] = "e.department_id = ?";
            $params[] = $department;
        }
        
        $whereClause = "WHERE " . implode(" AND ", $whereConditions);
        
        $query = "SELECT 
                    DATE_FORMAT(a.attendance_date, '%Y-%m-%d') as date,
                    COUNT(DISTINCT a.employee_id) as total_employees,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    AVG(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) * 100 as attendance_rate,
                    AVG(a.working_hours) as avg_working_hours
                FROM attendance a
                JOIN employees e ON a.employee_id = e.employee_id
                {$whereClause}
                GROUP BY DATE(a.attendance_date)
                ORDER BY a.attendance_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by week for better visualization
        $weeklyGroups = [];
        foreach ($monthlyData as $day) {
            $week = date('W', strtotime($day['date']));
            if (!isset($weeklyGroups[$week])) {
                $weeklyGroups[$week] = [
                    'week' => $week,
                    'present' => 0,
                    'late' => 0,
                    'absent' => 0,
                    'total_days' => 0
                ];
            }
            
            $weeklyGroups[$week]['present'] += $day['present_count'];
            $weeklyGroups[$week]['late'] += $day['late_count'];
            $weeklyGroups[$week]['absent'] += $day['absent_count'];
            $weeklyGroups[$week]['total_days']++;
        }
        
        $chartData = [
            'labels' => [],
            'datasets' => [
                'present' => [],
                'late' => [],
                'absent' => []
            ]
        ];
        
        foreach ($weeklyGroups as $week) {
            $chartData['labels'][] = "Week " . $week['week'];
            $chartData['datasets']['present'][] = $week['present'];
            $chartData['datasets']['late'][] = $week['late'];
            $chartData['datasets']['absent'][] = $week['absent'];
        }
        
        return [
            'monthly_data' => $monthlyData,
            'chart_data' => $chartData
        ];
    }
    
    private function getQuarterlyAttendance($department = null) {
        $whereConditions = ["a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)"];
        $params = [];
        
        if ($department) {
            $whereConditions[] = "e.department_id = ?";
            $params[] = $department;
        }
        
        $whereClause = "WHERE " . implode(" AND ", $whereConditions);
        
        $query = "SELECT 
                    DATE_FORMAT(a.attendance_date, '%Y-%m') as month,
                    COUNT(DISTINCT a.employee_id) as total_employees,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    AVG(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) * 100 as attendance_rate,
                    AVG(a.working_hours) as avg_working_hours,
                    AVG(a.overtime_hours) as avg_overtime_hours
                FROM attendance a
                JOIN employees e ON a.employee_id = e.employee_id
                {$whereClause}
                GROUP BY DATE_FORMAT(a.attendance_date, '%Y-%m')
                ORDER BY month";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $quarterlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $chartData = [
            'labels' => [],
            'datasets' => [
                'attendance_rate' => [],
                'avg_working_hours' => []
            ]
        ];
        
        foreach ($quarterlyData as $month) {
            $chartData['labels'][] = date('M Y', strtotime($month['month'] . '-01'));
            $chartData['datasets']['attendance_rate'][] = round($month['attendance_rate'], 1);
            $chartData['datasets']['avg_working_hours'][] = round($month['avg_working_hours'], 1);
        }
        
        return [
            'quarterly_data' => $quarterlyData,
            'chart_data' => $chartData
        ];
    }
    
    private function getYearlyAttendance($department = null) {
        $whereConditions = ["a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)"];
        $params = [];
        
        if ($department) {
            $whereConditions[] = "e.department_id = ?";
            $params[] = $department;
        }
        
        $whereClause = "WHERE " . implode(" AND ", $whereConditions);
        
        $query = "SELECT 
                    DATE_FORMAT(a.attendance_date, '%Y-%m') as month,
                    COUNT(DISTINCT a.employee_id) as total_employees,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    AVG(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) * 100 as attendance_rate
                FROM attendance a
                JOIN employees e ON a.employee_id = e.employee_id
                {$whereClause}
                GROUP BY DATE_FORMAT(a.attendance_date, '%Y-%m')
                ORDER BY month";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $yearlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $chartData = [
            'labels' => [],
            'datasets' => [
                'attendance_rate' => []
            ]
        ];
        
        foreach ($yearlyData as $month) {
            $chartData['labels'][] = date('M Y', strtotime($month['month'] . '-01'));
            $chartData['datasets']['attendance_rate'][] = round($month['attendance_rate'], 1);
        }
        
        return [
            'yearly_data' => $yearlyData,
            'chart_data' => $chartData
        ];
    }
    
    private function getAttendanceSummary($department = null) {
        $whereConditions = ["a.attendance_date = CURDATE()"];
        $params = [];
        
        if ($department) {
            $whereConditions[] = "e.department_id = ?";
            $params[] = $department;
        }
        
        $whereClause = "WHERE " . implode(" AND ", $whereConditions);
        
        $query = "SELECT 
                    COUNT(DISTINCT a.employee_id) as total_employees,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_today,
                    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_today,
                    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_today,
                    SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) as half_day_today,
                    AVG(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) * 100 as today_attendance_rate,
                    AVG(a.working_hours) as avg_working_hours_today,
                    SUM(a.overtime_hours) as total_overtime_today
                FROM attendance a
                JOIN employees e ON a.employee_id = e.employee_id
                {$whereClause}";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $todaySummary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Weekly summary
        $whereConditions[0] = "a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $whereClause = "WHERE " . implode(" AND ", $whereConditions);
        
        $weeklyQuery = "SELECT 
                       AVG(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) * 100 as weekly_attendance_rate,
                       AVG(a.working_hours) as avg_weekly_hours,
                       SUM(a.overtime_hours) as total_weekly_overtime
                       FROM attendance a
                       JOIN employees e ON a.employee_id = e.employee_id
                       {$whereClause}";
        
        $stmt = $this->conn->prepare($weeklyQuery);
        $stmt->execute($params);
        $weeklySummary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'today' => $todaySummary,
            'week' => $weeklySummary
        ];
    }
    
    private function getAttendanceTrends($department = null) {
        $whereConditions = ["a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"];
        $params = [];
        
        if ($department) {
            $whereConditions[] = "e.department_id = ?";
            $params[] = $department;
        }
        
        $whereClause = "WHERE " . implode(" AND ", $whereConditions);
        
        // Late arrivals trend
        $lateTrendQuery = "SELECT 
                          DATE(a.attendance_date) as date,
                          COUNT(*) as late_count
                          FROM attendance a
                          JOIN employees e ON a.employee_id = e.employee_id
                          {$whereClause}
                          AND a.status = 'late'
                          GROUP BY DATE(a.attendance_date)
                          ORDER BY date";
        
        $stmt = $this->conn->prepare($lateTrendQuery);
        $stmt->execute($params);
        $lateTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Early departures trend
        $earlyDepartureQuery = "SELECT 
                               DATE(a.attendance_date) as date,
                               COUNT(*) as early_departures
                               FROM attendance a
                               JOIN employees e ON a.employee_id = e.employee_id
                               {$whereClause}
                               AND a.working_hours < 8
                               AND a.status IN ('present', 'late')
                               GROUP BY DATE(a.attendance_date)
                               ORDER BY date";
        
        $stmt = $this->conn->prepare($earlyDepartureQuery);
        $stmt->execute($params);
        $earlyDepartureTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'late_arrivals' => $lateTrends,
            'early_departures' => $earlyDepartureTrends
        ];
    }
    
    private function getDepartmentComparison() {
        $query = "SELECT 
                 d.department_name,
                 COUNT(DISTINCT e.employee_id) as total_employees,
                 AVG(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) * 100 as attendance_rate,
                 AVG(a.working_hours) as avg_working_hours,
                 AVG(a.overtime_hours) as avg_overtime_hours,
                 SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as total_late,
                 SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as total_absent
                 FROM departments d
                 LEFT JOIN employees e ON d.department_id = e.department_id
                 LEFT JOIN attendance a ON e.employee_id = a.employee_id AND a.attendance_date = CURDATE()
                 GROUP BY d.department_id, d.department_name
                 ORDER BY attendance_rate DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Process request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $database = new Database();
    $db = $database->getConnection();
    $chartManager = new AttendanceChartManager($db);
    
    $period = $_GET['period'] ?? 'week';
    $department = $_GET['department'] ?? null;
    
    $result = $chartManager->getAttendanceChartData($period, $department);
    echo json_encode($result);
}
?>