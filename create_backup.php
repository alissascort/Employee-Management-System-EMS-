<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin'){echo json_encode(['success'=>false,'message'=>'Admin access required']);exit;}
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();
try{
    $dir=__DIR__.'/backups';if(!is_dir($dir))mkdir($dir,0755,true);
    $ts=date('Y-m-d_H-i-s');$file="$dir/backup_".DB_NAME."_$ts.sql.gz";
    $cmd=sprintf('mysqldump --host=%s --user=%s --password=%s %s | gzip > %s 2>&1',escapeshellarg(DB_HOST),escapeshellarg(DB_USER),escapeshellarg(DB_PASS),escapeshellarg(DB_NAME),escapeshellarg($file));
    exec($cmd,$out,$rc);
    if($rc!==0){throw new Exception('Backup failed');}
    $sz=filesize($file);
    $pdo->prepare("INSERT INTO backup_history(backup_date,file_name,file_size,backup_type,status,created_by)VALUES(NOW(),?,?,'Full','Success',?)")->execute([basename($file),$sz,$_SESSION['admin_id']]);
    $pdo->prepare("INSERT INTO audit_logs(user_id,action,details,ip_address,timestamp)VALUES(?,'BACKUP',?,?,NOW())")->execute([$_SESSION['admin_id'],"Backup: ".basename($file)." (".round($sz/1024/1024,2)." MB)",$_SERVER['REMOTE_ADDR']??'127.0.0.1']);
    echo json_encode(['success'=>true,'message'=>'Backup created','size'=>round($sz/1024/1024,2).' MB']);
}catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
?>
