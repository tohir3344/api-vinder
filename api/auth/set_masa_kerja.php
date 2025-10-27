<?php
declare(strict_types=1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true) ?: [];

$id   = isset($in['id']) ? (int)$in['id'] : 0;
$masa = isset($in['masa_kerja']) ? trim((string)$in['masa_kerja']) : '';

if ($id <= 0 || $masa === '') {
  http_response_code(400);
  echo json_encode(['success'=>false,'message'=>'id/masa_kerja invalid']); exit;
}

$stmt = $conn->prepare("UPDATE users SET masa_kerja = ? WHERE id = ?");
$stmt->bind_param("si", $masa, $id);
$stmt->execute();

if ($stmt->affected_rows >= 0) {
  echo json_encode(['success'=>true, 'updated_id'=>$id, 'masa_kerja'=>$masa], JSON_UNESCAPED_UNICODE);
} else {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'update failed']);
}
