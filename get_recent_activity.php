<?php
// api/getRecentActivities.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once 'db_connect.php';

$database = new Database();
$db = $database->getConnection();

$limit = isset($_GET['limit']) ? $_GET['limit'] : 10;

$query = "SELECT * FROM activities ORDER BY created_at DESC LIMIT :limit";
$stmt = $db->prepare($query);
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$activities = array();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $activities[] = $row;
}

http_response_code(200);
echo json_encode($activities);
?>