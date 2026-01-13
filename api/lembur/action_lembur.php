<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST");

include '../koneksi.php';

$id = $_POST['id'];
$action = $_POST['action']; // 'approve' atau 'reject'

if ($action == 'approve') {
    $status = 'approved';
} else if ($action == 'reject') {
    $status = 'rejected';
} else {
    die(json_encode(["success" => false, "message" => "Action tidak valid"]));
}

$sql = "UPDATE lembur SET status = '$status' WHERE id = '$id'";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["success" => true, "message" => "Berhasil di-" . $status]);
} else {
    echo json_encode(["success" => false, "message" => "Error: " . $conn->error]);
}
?>