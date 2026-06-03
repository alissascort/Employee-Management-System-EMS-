<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin'){echo json_encode(['success'=>false,'message'=>'Admin access required']);exit;}
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();
$input=json_decode(file_get_contents('php://input'),true);
$uid=(int)($input['id']??0);$name=trim($input['name']??'');$email=trim($input['email']??'');$role=$input['role']??null;$active=isset($input['active'])?(bool)$input['active']:null;$password=$input['password']??null;
if($uid<=0||empty($name)||empty($email)){echo json_encode(['success'=>false,'message'=>'Invalid data']);exit;}
try{
    $check=$pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");$check->execute([$email,$uid]);
    if($check->rowCount()>0){echo json_encode(['success'=>false,'message'=>'Email in use']);exit;}
    $pdo->beginTransaction();
    $up=["full_name=?",'email=?'];$par=[$name,$email];
    if($role){$up[]='role=?';$par[]=$role;}
    if($active!==null){$up[]='status=?';$par[]=$active?'Active':'Inactive';}
    if($password&&strlen($password)>=8){$up[]='password=?';$par[]=password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);}
    $par[]=$uid;
    $pdo->prepare("UPDATE users SET ".implode(',',$up)." WHERE id=?")->execute($par);
    $pdo->prepare("INSERT INTO audit_logs(user_id,action,details,ip_address,timestamp)VALUES(?,'USER_UPDATE',?,?,NOW())")->execute([$_SESSION['admin_id'],"Updated user ID:$uid",$_SERVER['REMOTE_ADDR']??'127.0.0.1']);
    $pdo->commit();
    echo json_encode(['success'=>true,'message'=>'User updated']);
}catch(PDOException $e){$pdo->rollBack();echo json_encode(['success'=>false,'message'=>'Error']);}
?>
