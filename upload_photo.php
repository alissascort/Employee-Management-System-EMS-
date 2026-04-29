<?php
header("Content-Type: application/json");

require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

$targetDir = "uploads/";
if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

if (!empty($_FILES['photo']['name'])) {
    $filename = basename($_FILES['photo']['name']);
    $targetFile = $targetDir . $filename;

    if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetFile)) {
        echo json_encode(['success' => true, 'filename' => $filename]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No file received']);
}
?>
