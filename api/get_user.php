<?php
declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');

// >>>> SESUAIKAN dengan nama file koneksi kamu persis (K besar/kecil)
require_once __DIR__ . '/../Koneksi.php';  // atau '../koneksi.php' jika memang kecil

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["status" => false, "message" => "ID user tidak ditemukan"]);
  exit;
}

try {
  $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($row = $res->fetch_assoc()) {
    if (!empty($row['foto'])) {
      // kalau kolom 'foto' cuma simpan nama file, tambahkan prefix URL
      if (stripos($row['foto'], 'http://') !== 0 && stripos($row['foto'], 'https://') !== 0) {
        $row['foto'] = 'http://penggajian.test/uploads/' . ltrim($row['foto'], '/'); // SESUAIKAN DOMAIN
      }
    }
    echo json_encode(["status" => true, "data" => $row], JSON_UNESCAPED_UNICODE);
  } else {
    http_response_code(404);
    echo json_encode(["status" => false, "message" => "User tidak ditemukan"]);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["status" => false, "message" => "Server error: ".$e->getMessage()]);
}