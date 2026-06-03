<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin'){echo json_encode(['success'=>false,'message'=>'Unauthorized']);exit;}
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();
$date=$_GET['date']??null;$action=$_GET['action']??null;$limit=min((int)($_GET['limit']??100),500);
try{
    $w=[];$p=[];
    if($date){$w[]="DATE(a.timestamp)=?";$p[]=$date;}
    if($action){$w[]="a.action=?";$p[]=$action;}
    $wh=!empty($w)?"WHERE ".implode(' AND ',$w):"";
    $sql="SELECT a.id,a.timestamp,a.action,a.details,a.ip_address,COALESCE(u.full_name,u.email,'System')as user_name FROM audit_logs a LEFT JOIN users u ON a.user_id=u.id $wh ORDER BY a.timestamp DESC LIMIT $limit";
    $stmt=$pdo->prepare($sql);$stmt->execute($p);$logs=$stmt->fetchAll();
    $fmt=array_map(function($l){return['id'=>(int)$l['id'],'timestamp'=>date('Y-m-d H:i:s',strtotime($l['timestamp'])),'user'=>htmlspecialchars($l['user_name']),'action'=>$l['action'],'details'=>htmlspecialchars($l['details']),'ip'=>$l['ip_address']];},$logs);
    echo json_encode(['success'=>true,'logs'=>$fmt,'total'=>count($fmt)]);
}catch(PDOException $e){echo json_encode(['success'=>false,'message'=>'Error']);}
?>
