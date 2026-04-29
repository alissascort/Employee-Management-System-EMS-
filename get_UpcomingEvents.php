<?php
//getUpcomingEvents.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once 'db_connect.php';

$database = new Database();
$db = $database->getConnection();

$limit = isset($_GET['limit']) ? $_GET['limit'] : 5;

$query = "SELECT * FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT :limit";
$stmt = $db->prepare($query);
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$events = array();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $events[] = $row;
}

http_response_code(200);
echo json_encode($events);
?>