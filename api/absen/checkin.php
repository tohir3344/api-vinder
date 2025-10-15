<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../koneksi.php';
// mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // aktifkan saat debug

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
  }

  $user_id = (int)($_POST['user_id'] ?? 0);
  $lat     = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
  $lng     = isset($_POST['lng']) ? (float)$_POST['lng'] : null;

  if ($user_id <= 0 || $lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'user_id, lat, lng wajib']); exit;
  }

  // --- upload foto (opsional) ---
  $fotoPath = null;
  if (!empty($_FILES['foto']['tmp_name']) && is_uploaded_file($_FILES['foto']['tmp_name'])) {
    $dir = __DIR__ . '/../uploads/absen';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $fname = 'masuk_' . $user_id . '_' . date('Ymd_His') . '.jpg';
    $dest  = $dir . '/' . $fname;
    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
      http_response_code(500);
      echo json_encode(['success'=>false,'message'=>'Upload foto gagal']); exit;
    }
    $fotoPath = 'uploads/absen/' . $fname;
  }

  // 1) pastikan ada baris hari ini (kalau belum, buat; kalau sudah, IGNORE)
  $ins = $conn->prepare("
    INSERT IGNORE INTO absen (user_id, tanggal, jam_masuk, masuk_lat, masuk_lng, foto_masuk)
    VALUES (?, CURDATE(), CURTIME(), ?, ?, ?)
  ");
  $ins->bind_param("idds", $user_id, $lat, $lng, $fotoPath);
  $ins->execute();

  // 2) isi kolom yang masih NULL saja
  $upd = $conn->prepare("
    UPDATE absen
       SET jam_masuk  = IFNULL(jam_masuk, CURTIME()),
           masuk_lat  = IFNULL(masuk_lat,  ?),
           masuk_lng  = IFNULL(masuk_lng,  ?),
           foto_masuk = IFNULL(foto_masuk, ?)
     WHERE user_id=? AND tanggal=CURDATE()
     LIMIT 1
  ");
  $upd->bind_param("ddsi", $lat, $lng, $fotoPath, $user_id);
  $upd->execute();

  // kirim balik row hari ini
  $sel = $conn->prepare("SELECT * FROM absen WHERE user_id=? AND tanggal=CURDATE() LIMIT 1");
  $sel->bind_param("i", $user_id);
  $sel->execute();
  $row = $sel->get_result()->fetch_assoc();

  echo json_encode(['success'=>true,'message'=>'Check-in OK','data'=>$row]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
