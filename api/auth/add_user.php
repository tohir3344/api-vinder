<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Jangan tampilkan notice ke output (biar JSON bersih)
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../Koneksi.php'; // pastikan file ini mendefinisikan $conn
if (!isset($conn) || !$conn) {
  http_response_code(500);
  echo json_encode(["success"=>false, "message"=>"DB connection not initialized"]);
  exit;
}

try {
  // Ambil data POST (multipart/form-data)
  $username       = $_POST['username']       ?? null;
  $password       = $_POST['password']       ?? null;
  $nama_lengkap   = $_POST['nama_lengkap']   ?? null;
  $tempat_lahir   = $_POST['tempat_lahir']   ?? null;
  $tanggal_lahir  = $_POST['tanggal_lahir']  ?? null;
  $email          = $_POST['email']          ?? null;
  $no_telepon     = $_POST['no_telepon']     ?? null;
  $alamat         = $_POST['alamat']         ?? null;
  $masa_kerja     = $_POST['masa_kerja']     ?? null;
  $role           = $_POST['role']           ?? "staff";

  // 🔹 TANGGAL MASUK (BARU)
  // name di form harus "tanggal_masuk"
  $tanggal_masuk  = $_POST['tanggal_masuk']  ?? null;

  // (opsional) kalau kosong, bisa di-set ke hari ini
  if ($tanggal_masuk === null || $tanggal_masuk === '') {
    // kalau kamu maunya wajib diisi, hapus blok ini dan treat sebagai error
    $tanggal_masuk = date('Y-m-d');
  }

  // Validasi dasar
  if (!$username || !$password || !$nama_lengkap) {
    echo json_encode([
      "success" => false,
      "message" => "Username, password, dan nama lengkap wajib diisi"
    ]);
    exit;
  }

  // Upload foto (opsional)
  $foto_path = null;
  if (isset($_FILES['foto']) && isset($_FILES['foto']['tmp_name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION) ?: 'jpg');
    $dir = __DIR__ . "/../uploads/foto_user/";
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $filename = uniqid("usr_", true) . "." . $ext;
    $target   = $dir . $filename;
    if (move_uploaded_file($_FILES['foto']['tmp_name'], $target)) {
      // Simpan path relatif agar aman dipakai front-end
      $foto_path = "uploads/foto_user/" . $filename;
    }
  }

  // (Sesuai permintaan) simpan password plain
  $pwd_to_store = $password;

  // 🔹 TAMBAH kolom tanggal_masuk di INSERT
  $sql = "INSERT INTO users (
            username, password, nama_lengkap, tempat_lahir, tanggal_lahir,
            email, no_telepon, alamat, masa_kerja, role, foto, tanggal_masuk, created_at
          )
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param(
    "ssssssssssss",
    $username,
    $pwd_to_store,
    $nama_lengkap,
    $tempat_lahir,
    $tanggal_lahir,
    $email,
    $no_telepon,
    $alamat,
    $masa_kerja,
    $role,
    $foto_path,
    $tanggal_masuk   // 🔹 ikut bind di sini
  );

  $ok = $stmt->execute();

  if ($ok) {
    echo json_encode([
      "success" => true,
      "message" => "Akun berhasil ditambahkan",
      "id"      => $stmt->insert_id,
      "foto"    => $foto_path,
    ]);
  } else {
    echo json_encode([
      "success" => false,
      "message" => "Gagal menambahkan akun"
    ]);
  }

  $stmt->close();

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "Server error: " . $e->getMessage()
  ]);
}
