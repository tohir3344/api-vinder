<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/../Koneksi.php';

// Ambil data POST
$id = $_POST['id'] ?? null;
$username = $_POST['username'] ?? null;
$password = $_POST['password'] ?? null;
$nama_lengkap = $_POST['nama_lengkap'] ?? null;
$tempat_lahir = $_POST['tempat_lahir'] ?? null;
$tanggal_lahir = $_POST['tanggal_lahir'] ?? null;
$no_telepon = $_POST['no_telepon'] ?? null;
$alamat = $_POST['alamat'] ?? null;

if (!$id) {
    echo json_encode(["success" => false, "message" => "ID user diperlukan"]);
    exit;
}

// Update user
$stmt = $koneksi->prepare("UPDATE users SET username=?, password=?, nama_lengkap=?, tempat_lahir=?, tanggal_lahir=?, no_telepon=?, alamat=? WHERE id=?");
$stmt->bind_param("sssssssi", $username, $password, $nama_lengkap, $tempat_lahir, $tanggal_lahir, $no_telepon, $alamat, $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Data user berhasil diperbarui"]);
} else {
    echo json_encode(["success" => false, "message" => "Gagal memperbarui data"]);
}
$stmt->close();
$koneksi->close();
?>