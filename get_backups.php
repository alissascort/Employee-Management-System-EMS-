<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin'){echo json_encode(['success'=>false,'message'=>'Unauthorized']);exit;}
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();
try{
    $db=$pdo->query("SELECT DATABASE()")->fetchColumn();
    $sz=$pdo->prepare("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2)FROM information_schema.tables WHERE table_schema=?");$sz->execute([$db]);$size=$sz->fetchColumn();
    $b=$pdo->prepare("SELECT id,backup_date,file_name,file_size,backup_type,status FROM backup_history ORDER BY backup_date DESC LIMIT 20");$b->execute();$backups=$b->fetchAll();
    $fmt=array_map(function($bk){return['id'=>(int)$bk['id'],'date'=>date('Y-m-d H:i',strtotime($bk['backup_date'])),'file_name'=>$bk['file_name'],'size'=>$bk['file_size']?round($bk['file_size']/1024/1024,2).' MB':'N/A','type'=>$bk['backup_type']?:'Full','status'=>$bk['status']?:'Success'];},$backups);
    $last=$pdo->query("SELECT backup_date FROM backup_history WHERE status='Success' ORDER BY backup_date DESC LIMIT 1")->fetchColumn();
    echo json_encode(['success'=>true,'last_backup'=>$last?date('Y-m-d H:i',strtotime($last)):'Never','db_size'=>($size?:0).' MB','backups'=>$fmt]);
}catch(PDOException $e){echo json_encode(['success'=>false,'message'=>'Error']);}
?>
