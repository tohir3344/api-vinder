<?php
// api/izin/izin_delete.php
declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';

try {
  $raw = file_get_contents('php://input');
  $in  = json_decode($raw, true);
  if (!is_array($in)) throw new Exception("Body harus JSON");

  $id = isset($in['id']) ? (int)$in['id'] : 0;
  if ($id <= 0) throw new Exception("id invalid");

  // Eksekusi delete
  $stmt = $conn->prepare("DELETE FROM izin WHERE id = ?");
  if (!$stmt) throw new Exception("Prepare gagal: ".$conn->error);
  $stmt->bind_param("i", $id);
  if (!$stmt->execute()) throw new Exception("Eksekusi gagal: ".$stmt->error);

  if ($stmt->affected_rows < 1) throw new Exception("Data tidak ditemukan / sudah dihapus");
  $stmt->close();

  echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  exit;
}
