<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin'){echo json_encode(['success'=>false,'message'=>'Admin access required']);exit;}
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();
$input=json_decode(file_get_contents('php://input'),true);
$uid=(int)($input['user_id']??0);
if($uid<=0){echo json_encode(['success'=>false,'message'=>'Invalid user ID']);exit;}
try{
    $u=$pdo->prepare("SELECT email,full_name FROM users WHERE id=?");$u->execute([$uid]);$user=$u->fetch();
    if(!$user){echo json_encode(['success'=>false,'message'=>'User not found']);exit;}
    $token=bin2hex(random_bytes(32));$exp=date('Y-m-d H:i:s',strtotime('+1 hour'));
    $pdo->prepare("UPDATE users SET reset_token=?,reset_token_expiry=? WHERE id=?")->execute([$token,$exp,$uid]);
    $link=(isset($_SERVER['HTTPS'])?'https':'http')."://{$_SERVER['HTTP_HOST']}/reset_password.php?token=$token";
    $msg="<html><body><h2>Password Reset</h2><p>Hello {$user['full_name']},</p><p>Click to reset: <a href='$link'>$link</a></p><p>Valid 1 hour.</p></body></html>";
    $headers="MIME-Version:1.0\r\nContent-Type:text/html;charset=UTF-8\r\nFrom:EMS<noreply@fortishield.com>\r\n";
    $sent=mail($user['email'],"Password Reset",$msg,$headers);
    $pdo->prepare("INSERT INTO audit_logs(user_id,action,details,ip_address,timestamp)VALUES(?,'PASSWORD_RESET',?,?,NOW())")->execute([$_SESSION['admin_id'],"Reset for: {$user['email']}",$_SERVER['REMOTE_ADDR']??'127.0.0.1']);
    echo json_encode(['success'=>true,'message'=>"Reset link sent to {$user['email']}",'email_sent'=>$sent]);
}catch(PDOException $e){echo json_encode(['success'=>false,'message'=>'Error']);}
?>
