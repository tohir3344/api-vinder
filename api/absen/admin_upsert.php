<?php
declare(strict_types=1);
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");

require_once __DIR__ . '/../Koneksi.php'; // pastikan huruf K besar

try {
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
  }

  $raw = file_get_contents("php://input");
  $json = json_decode($raw, true);
  if (!is_array($json)) throw new Exception("Invalid JSON body");

  $mode       = isset($json['mode']) ? strtolower(trim((string)$json['mode'])) : 'create';
  $id         = isset($json['id']) ? (int)$json['id'] : null;
  $user_id    = isset($json['user_id']) ? (int)$json['user_id'] : 0;
  $tanggal    = isset($json['tanggal']) ? trim((string)$json['tanggal']) : '';
  $jam_masuk  = array_key_exists('jam_masuk', $json) ? ($json['jam_masuk'] ?: null) : null;
  $jam_keluar = array_key_exists('jam_keluar', $json) ? ($json['jam_keluar'] ?: null) : null;
  $status     = isset($json['status']) ? strtoupper(trim((string)$json['status'])) : 'HADIR';
  $alasan     = array_key_exists('alasan', $json) ? ($json['alasan'] ?: null) : null;

  if ($user_id <= 0 || $tanggal === '') throw new Exception("user_id dan tanggal wajib diisi");

  // Validasi status
  $allowed = ['HADIR','IZIN','SAKIT','ALPHA'];
  if (!in_array($status, $allowed, true)) throw new Exception("Status tidak valid");

  if ($mode === 'update') {
    if (!$id) throw new Exception("id wajib untuk update");

    $sql = "UPDATE absen
            SET user_id=?, tanggal=?, jam_masuk=?, jam_keluar=?, status=?, alasan=?
            WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
      "isssssi",
      $user_id,
      $tanggal,
      $jam_masuk,
      $jam_keluar,
      $status,
      $alasan,
      $id
    );
    if (!$stmt->execute()) throw new Exception("Gagal update: ".$stmt->error);

    echo json_encode(["success"=>true, "mode"=>"update", "affected"=>$stmt->affected_rows],
      JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  // mode create (optional: cegah duplikat user_id+tanggal)
  // Jika punya UNIQUE (user_id, tanggal), bisa pakai INSERT ... ON DUPLICATE KEY UPDATE
  $sql = "INSERT INTO absen (user_id, tanggal, jam_masuk, jam_keluar, status, alasan)
          VALUES (?, ?, ?, ?, ?, ?)";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param(
    "isssss",
    $user_id,
    $tanggal,
    $jam_masuk,
    $jam_keluar,
    $status,
    $alasan
  );
  if (!$stmt->execute()) throw new Exception("Gagal insert: ".$stmt->error);

  echo json_encode(["success"=>true, "mode"=>"create", "id"=>$conn->insert_id],
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(["success"=>false, "message"=>"Server error", "error"=>$e->getMessage()],
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
