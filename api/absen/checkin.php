<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD']==='OPTIONS'){ http_response_code(200); exit; }

require_once __DIR__ . '/../koneksi.php';

$user_id = (int)($_POST['user_id'] ?? 0);
if ($user_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'user_id wajib']); exit; }

$stmt = $conn->prepare("
  INSERT INTO absen (user_id, tanggal, jam_masuk)
  VALUES (?, CURDATE(), CURTIME())
  ON DUPLICATE KEY UPDATE jam_masuk = COALESCE(jam_masuk, VALUES(jam_masuk))
");
$stmt->bind_param("i", $user_id);
$stmt->execute();

echo json_encode(['success'=>true,'message'=>'Check-in OK']);
