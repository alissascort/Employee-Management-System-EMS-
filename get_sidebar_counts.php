<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin'){echo json_encode(['success'=>false,'message'=>'Unauthorized']);exit;}
require_once 'db_connect.php';
$db = new Database();
$pdo = $db->getConnection();
try{
    $u=$pdo->query("SELECT COUNT(*)FROM users")->fetchColumn();
    $e=$pdo->query("SELECT COUNT(*)FROM staff_profiles")->fetchColumn();
    $d=$pdo->query("SELECT COUNT(*)FROM departments WHERE status='Active'")->fetchColumn();
    $l=$pdo->query("SELECT COUNT(*)FROM leave_requests WHERE status='Pending'")->fetchColumn();
    echo json_encode(['success'=>true,'counts'=>['users'=>(int)$u,'employees'=>(int)$e,'departments'=>(int)$d,'leave_requests'=>(int)$l]]);
}catch(PDOException $e){echo json_encode(['success'=>false,'message'=>'Error']);}
?>
