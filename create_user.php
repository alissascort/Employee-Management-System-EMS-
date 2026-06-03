<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin'){echo json_encode(['success'=>false,'message'=>'Admin access required']);exit;}
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();
$input=json_decode(file_get_contents('php://input'),true);
$name=trim($input['name']??'');$email=trim($input['email']??'');$password=$input['password']??'';$role=$input['role']??'employee';$active=isset($input['active'])?(bool)$input['active']:true;
if(empty($name)||empty($email)||!filter_var($email,FILTER_VALIDATE_EMAIL)){echo json_encode(['success'=>false,'message'=>'Valid name and email required']);exit;}
if(empty($password)||strlen($password)<8){echo json_encode(['success'=>false,'message'=>'Password must be 8+ characters']);exit;}
try{
    $check=$pdo->prepare("SELECT id FROM users WHERE email=?");$check->execute([$email]);
    if($check->rowCount()>0){echo json_encode(['success'=>false,'message'=>'Email already exists']);exit;}
    $pdo->beginTransaction();
    $hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);
    $stmt=$pdo->prepare("INSERT INTO users(email,password,full_name,role,status,created_at)VALUES(?,?,?,?,?,NOW())");
    $stmt->execute([$email,$hash,$name,$role,$active?'Active':'Inactive']);
    $uid=$pdo->lastInsertId();
    if($role==='employee'){$ns=explode(' ',$name,2);$pdo->prepare("INSERT INTO staff_profiles(user_id,firstname,lastname,email,status,registration_date)VALUES(?,?,?,?,'Active',CURDATE())")->execute([$uid,$ns[0],$ns[1]??'',$email]);}
    $pdo->prepare("INSERT INTO audit_logs(user_id,action,details,ip_address,timestamp)VALUES(?,'USER_CREATE',?,?,NOW())")->execute([$_SESSION['admin_id'],"Created: $email ($role)",$_SERVER['REMOTE_ADDR']??'127.0.0.1']);
    $pdo->commit();
    echo json_encode(['success'=>true,'message'=>'User created','user_id'=>(int)$uid]);
}catch(PDOException $e){$pdo->rollBack();echo json_encode(['success'=>false,'message'=>'Error creating user']);}
?>
