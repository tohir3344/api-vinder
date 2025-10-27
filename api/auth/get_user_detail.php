<?php
declare(strict_types=1);
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../Koneksi.php';

function ok($data) {
  echo json_encode(["success" => true, "data" => $data], JSON_UNESCAPED_UNICODE);
  exit;
}
function bad($msg, $http = 400) {
  http_response_code($http);
  echo json_encode(["success" => false, "message" => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  if (!isset($conn) || !$conn instanceof mysqli) {
    bad("Koneksi database tidak tersedia.", 500);
  }

  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($id <= 0) {
    bad("ID tidak valid", 400);
  }

  $sql = "SELECT id, username, password, nama_lengkap, tempat_lahir, tanggal_lahir, no_telepon, alamat
          FROM users WHERE id = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    bad("Gagal mempersiapkan query: ".$conn->error, 500);
  }

  $stmt->bind_param("i", $id);
  if (!$stmt->execute()) {
    bad("Eksekusi query gagal: ".$stmt->error, 500);
  }

  $res = $stmt->get_result();
  if ($res && $row = $res->fetch_assoc()) {
    ok($row);
  } else {
    bad("User tidak ditemukan", 404);
  }
} catch (Throwable $e) {
  bad("Server error: ".$e->getMessage(), 500);
}
