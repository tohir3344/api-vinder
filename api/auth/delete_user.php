<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, DELETE");

require_once __DIR__ . '/../Koneksi.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(["success" => false, "message" => "ID user diperlukan"]);
    exit;
}

// Hapus user
$stmt = $koneksi->prepare("DELETE FROM users WHERE id=?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "User berhasil dihapus"]);
} else {
    echo json_encode(["success" => false, "message" => "Gagal menghapus user"]);
}

$stmt->close();
$koneksi->close();
?>