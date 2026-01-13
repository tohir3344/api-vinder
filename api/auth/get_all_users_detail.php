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
function bad($msg, $http = 500) {
  http_response_code($http);
  echo json_encode(["success" => false, "message" => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  if (!isset($conn) || !$conn instanceof mysqli) {
    bad("Koneksi database tidak tersedia.", 500);
  }

  // TAMBAHAN: Tambahkan lembur ke dalam SELECT dan COALESCE
  $sql = "SELECT id, username, 
                 COALESCE(nama_lengkap,'') AS nama_lengkap, 
                 COALESCE(gaji, 0) AS gaji,
                 COALESCE(lembur, 0) AS lembur
          FROM users
          ORDER BY id ASC";
          
  $res = $conn->query($sql);
  if ($res === false) {
    bad("Query gagal: " . $conn->error, 500);
  }

  $users = [];
  while ($row = $res->fetch_assoc()) {
    $users[] = [
      "id"           => (int)$row["id"],
      "username"     => (string)$row["username"],
      "nama_lengkap" => $row["nama_lengkap"] !== "" ? (string)$row["nama_lengkap"] : null,
      "gaji"         => is_null($row["gaji"]) ? null : (int)$row["gaji"],
      // TAMBAHAN: Kirim data lembur ke aplikasi
      "lembur"       => is_null($row["lembur"]) ? null : (int)$row["lembur"],
    ];
  }

  ok($users);
} catch (Throwable $e) {
  bad("Server error: " . $e->getMessage(), 500);
}