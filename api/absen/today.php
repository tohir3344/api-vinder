<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../koneksi.php';

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'user_id wajib']); exit; }

$tgl = date('Y-m-d');

$stmt = $conn->prepare("SELECT tanggal, jam_masuk, jam_keluar FROM absen WHERE user_id=? AND tanggal=? LIMIT 1");
$stmt->bind_param("is", $user_id, $tgl);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

echo json_encode([
  'success' => true,
  'data'    => $row ?: ['tanggal'=>$tgl, 'jam_masuk'=>null, 'jam_keluar'=>null]
]);
