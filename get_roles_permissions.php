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
    // Try to get from role_permissions table
    $stmt = $pdo->query("
        SELECT rp.*, r.role_name, 
               (SELECT COUNT(*) FROM users WHERE role = r.role_name) as user_count
        FROM role_permissions rp 
        JOIN roles r ON rp.role_id = r.id
    ");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($roles) > 0) {
        echo json_encode(['success' => true, 'roles' => $roles]);
    } else {
        // Fallback: return roles from users table with default permissions
        $stmt2 = $pdo->query("
            SELECT role, COUNT(*) as user_count 
            FROM users 
            GROUP BY role
        ");
        $userRoles = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        
        $defaultPerms = [
            'admin' => ['can_manage_users'=>1,'can_manage_payroll'=>1,'can_manage_attendance'=>1,'can_view_reports'=>1,'can_configure_system'=>1,'can_view_audit'=>1],
            'employee' => ['can_manage_users'=>0,'can_manage_payroll'=>0,'can_manage_attendance'=>1,'can_view_reports'=>0,'can_configure_system'=>0,'can_view_audit'=>0],
            'cso' => ['can_manage_users'=>0,'can_manage_payroll'=>0,'can_manage_attendance'=>1,'can_view_reports'=>1,'can_configure_system'=>0,'can_view_audit'=>1],
            'hr' => ['can_manage_users'=>2,'can_manage_payroll'=>1,'can_manage_attendance'=>1,'can_view_reports'=>1,'can_configure_system'=>0,'can_view_audit'=>0],
            'Dept Manager' => ['can_manage_users'=>2,'can_manage_payroll'=>0,'can_manage_attendance'=>1,'can_view_reports'=>1,'can_configure_system'=>0,'can_view_audit'=>0]
        ];
        
        $roles = array_map(function($r) use ($defaultPerms) {
            $role = $r['role'] ?: 'employee';
            $perms = $defaultPerms[$role] ?? $defaultPerms['employee'];
            return array_merge([
                'id' => 0,
                'role_name' => $role,
                'user_count' => (int)$r['user_count']
            ], $perms);
        }, $userRoles);
        
        echo json_encode(['success' => true, 'roles' => $roles]);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
