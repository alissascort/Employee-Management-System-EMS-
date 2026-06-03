<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin' && !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();
try {
    $stmt = $pdo->prepare("SELECT u.id, u.email, u.full_name, u.role, u.status, u.last_login, u.profile_photo, u.created_at FROM users u ORDER BY u.created_at DESC");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $formatted = array_map(function($u) {
        return ['id'=>(int)$u['id'],'full_name'=>htmlspecialchars($u['full_name']?:explode('@',$u['email'])[0]),'email'=>$u['email'],'role'=>$u['role']?:'User','status'=>$u['status']?:'Active','last_login'=>$u['last_login']?date('Y-m-d H:i',strtotime($u['last_login'])):'Never','profile_photo'=>$u['profile_photo']?:'Parrot.JPG'];
    }, $users);
    echo json_encode(['success'=>true,'users'=>$formatted,'total'=>count($formatted)]);
} catch(PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
?>
