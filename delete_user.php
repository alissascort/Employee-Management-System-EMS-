<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin'){echo json_encode(['success'=>false,'message'=>'Admin access required']);exit;}
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();
$input=json_decode(file_get_contents('php://input'),true);
$uid=(int)($input['user_id']??0);
if($uid<=0){echo json_encode(['success'=>false,'message'=>'Invalid ID']);exit;}
if($uid===(int)$_SESSION['admin_id']){echo json_encode(['success'=>false,'message'=>'Cannot delete yourself']);exit;}
try{
    $u=$pdo->prepare("SELECT email,full_name,role FROM users WHERE id=?");$u->execute([$uid]);$user=$u->fetch();
    if(!$user){echo json_encode(['success'=>false,'message'=>'Not found']);exit;}
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM staff_profiles WHERE user_id=?")->execute([$uid]);
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
    $pdo->prepare("INSERT INTO audit_logs(user_id,action,details,ip_address,timestamp)VALUES(?,'USER_DELETE',?,?,NOW())")->execute([$_SESSION['admin_id'],"Deleted: {$user['email']} ({$user['role']})",$_SERVER['REMOTE_ADDR']??'127.0.0.1']);
    $pdo->commit();
    echo json_encode(['success'=>true,'message'=>"User {$user['email']} deleted"]);
}catch(PDOException $e){$pdo->rollBack();echo json_encode(['success'=>false,'message'=>'Error']);}
?>
