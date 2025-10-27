<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/../Koneksi.php';

// Ambil data POST
$username = $_POST['username'] ?? null;
$password = $_POST['password'] ?? null;
$nama_lengkap = $_POST['nama_lengkap'] ?? null;
$tempat_lahir = $_POST['tempat_lahir'] ?? null;
$tanggal_lahir = $_POST['tanggal_lahir'] ?? null;
$email = $_POST['email'] ?? null;
$no_telepon = $_POST['no_telepon'] ?? null;
$alamat = $_POST['alamat'] ?? null;
$masa_kerja = $_POST['masa_kerja'] ?? null;
$role = $_POST['role'] ?? "staff";

// Validasi
if (!$username || !$password || !$nama_lengkap) {
    echo json_encode(["success" => false, "message" => "Username, password, dan nama lengkap wajib diisi"]);
    exit;
}

// Upload foto jika ada
$foto_path = null;
if (isset($_FILES['foto']) && $_FILES['foto']['error'] === 0) {
    $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
    $target_dir = "../uploads/foto_user/";
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
    $filename = uniqid() . "." . $ext;
    $target_file = $target_dir . $filename;
    if (move_uploaded_file($_FILES['foto']['tmp_name'], $target_file)) {
        $foto_path = $target_file;
    }
}

// Simpan password tanpa hash sesuai permintaan
$hashed_password = $password;

// Insert ke database
$stmt = $koneksi->prepare("INSERT INTO users (username, password, nama_lengkap, tempat_lahir, tanggal_lahir, email, no_telepon, alamat, masa_kerja, role, foto, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("sssssssssss", $username, $hashed_password, $nama_lengkap, $tempat_lahir, $tanggal_lahir, $email, $no_telepon, $alamat, $masa_kerja, $role, $foto_path);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Akun berhasil ditambahkan"]);
} else {
    echo json_encode(["success" => false, "message" => "Gagal menambahkan akun"]);
}

$stmt->close();
$koneksi->close();
?>