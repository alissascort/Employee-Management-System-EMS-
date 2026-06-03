<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin'){echo json_encode(['success'=>false,'message'=>'Admin access required']);exit;}
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();
$input=json_decode(file_get_contents('php://input'),true);
try{
    $pdo->beginTransaction();
    $settings=['company_name'=>$input['company']??'Fortishield-Matrix','admin_email'=>$input['email']??'','session_timeout'=>(int)($input['timeout']??30),'password_min_length'=>(int)($input['passLength']??8),'work_start_time'=>$input['workStart']??'08:00','work_end_time'=>$input['workEnd']??'17:00','two_factor_auth'=>($input['twoFA']??false)?'1':'0','maintenance_mode'=>($input['maintenance']??false)?'1':'0'];
    $stmt=$pdo->prepare("INSERT INTO system_config(setting_key,setting_value,updated_by,updated_at)VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()");
    foreach($settings as $k=>$v){$stmt->execute([$k,$v,$_SESSION['admin_id']]);}
    $pdo->prepare("INSERT INTO audit_logs(user_id,action,details,ip_address,timestamp)VALUES(?,'CONFIG_CHANGE',?,?,NOW())")->execute([$_SESSION['admin_id'],"Config updated",$_SERVER['REMOTE_ADDR']??'127.0.0.1']);
    $pdo->commit();
    echo json_encode(['success'=>true,'message'=>'Configuration saved']);
}catch(PDOException $e){$pdo->rollBack();echo json_encode(['success'=>false,'message'=>'Error']);}
?>
