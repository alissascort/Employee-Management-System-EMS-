<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin'){echo json_encode(['success'=>false,'message'=>'Unauthorized']);exit;}
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();
try{
    $tu=$pdo->query("SELECT COUNT(*)FROM users")->fetchColumn();
    $lm=$pdo->query("SELECT COUNT(*)FROM users WHERE created_at<DATE_SUB(NOW(),INTERVAL 1 MONTH)")->fetchColumn();
    $uc=$lm>0?round((($tu-$lm)/$lm)*100):0;
    $at=$pdo->query("SELECT COUNT(DISTINCT user_id)FROM attendance WHERE date=CURDATE()AND status IN('present','present_late')")->fetchColumn();
    $ap=$tu>0?round(($at/$tu)*100):0;
    $pr=$pdo->query("SELECT COUNT(*)FROM leave_requests WHERE status='Pending'")->fetchColumn();
    $yp=$pdo->query("SELECT COUNT(*)FROM leave_requests WHERE status='Pending'AND created_at<DATE_SUB(NOW(),INTERVAL 1 DAY)")->fetchColumn();
    $pc=$pr-$yp;
    $fl=$pdo->query("SELECT COUNT(*)FROM audit_logs WHERE action='LOGIN_FAILED'AND DATE(timestamp)=CURDATE()")->fetchColumn();
    $yf=$pdo->query("SELECT COUNT(*)FROM audit_logs WHERE action='LOGIN_FAILED'AND DATE(timestamp)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)")->fetchColumn();
    $fc=$fl-$yf;
    $dp=$pdo->query("SELECT COUNT(*)FROM departments WHERE status='Active'")->fetchColumn();
    $la=$pdo->query("SELECT COUNT(*)FROM leave_requests WHERE status='Approved'AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn();
    $act=$pdo->query("SELECT DATE(timestamp)d,COUNT(DISTINCT user_id)c FROM audit_logs WHERE action='LOGIN'AND timestamp>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)GROUP BY DATE(timestamp)ORDER BY d")->fetchAll();
    $al=[];$av=[];
    for($i=6;$i>=0;$i--){$dt=date('D',strtotime("-$i days"));$al[]=$dt;$av[]=0;}
    foreach($act as $r){$idx=array_search(date('D',strtotime($r['d'])),$al);if($idx!==false)$av[$idx]=(int)$r['c'];}
    $rl=$pdo->query("SELECT role,COUNT(*)c FROM users GROUP BY role ORDER BY c DESC")->fetchAll();
    $rla=[];$rva=[];
    foreach($rl as $r){$rla[]=$r['role']?:'Unassigned';$rva[]=(int)$r['c'];}
    echo json_encode(['success'=>true,'stats'=>['totalEmployees'=>(int)$tu,'totalEmployeesChange'=>$uc,'activeAttendanceToday'=>(int)$at,'activeAttendancePercentage'=>$ap,'pendingRequests'=>(int)$pr,'pendingChange'=>$pc,'failedLogins'=>(int)$fl,'failedLoginChange'=>$fc,'departments'=>(int)$dp,'leave'=>['pending'=>(int)$pr,'approved'=>(int)$la],'activityData'=>['labels'=>$al,'values'=>$av],'roleData'=>['labels'=>$rla,'values'=>$rva]]]);
}catch(PDOException $e){echo json_encode(['success'=>false,'message'=>'Error']);}
?>
