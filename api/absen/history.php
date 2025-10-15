<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../koneksi.php';

$user_id = (int)($_GET['user_id'] ?? 0);
$limit   = (int)($_GET['limit'] ?? 7);
if ($user_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'user_id wajib']); exit; }
if ($limit < 1 || $limit > 30) $limit = 7;

$stmt = $conn->prepare("
  SELECT tanggal, jam_masuk, jam_keluar
  FROM absen
  WHERE user_id=? AND tanggal < CURDATE()
  ORDER BY tanggal DESC
  LIMIT ?
");
$stmt->bind_param("ii", $user_id, $limit);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($r = $res->fetch_assoc()) { $out[] = $r; }

echo json_encode(['success'=>true, 'data'=>$out]);
