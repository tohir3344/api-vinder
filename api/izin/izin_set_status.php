<?php
// api/izin/izin_set_status.php
declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';

try {
  $raw = file_get_contents('php://input');
  $in  = json_decode($raw, true) ?: [];

  $id     = isset($in['id']) ? (int)$in['id'] : 0;
  $status = isset($in['status']) ? trim((string)$in['status']) : '';

  if ($id <= 0) throw new Exception("id invalid");
  $allowed = ['pending','disetujui','ditolak'];
  if (!in_array($status, $allowed, true)) throw new Exception("status invalid");

  $stmt = $conn->prepare("UPDATE izin SET status = ? WHERE id = ?");
  $stmt->bind_param("si", $status, $id);
  $stmt->execute();

  echo json_encode(['success' => true, 'id' => $id, 'status' => $status], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
