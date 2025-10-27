<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';

// ===== Jam kerja (samakan dengan client & upsert) =====
const START_TIME = '07:40:00';
const END_TIME   = '17:20:00';
function isOutsideWorkingNow(): bool {
  $now = date('H:i:s');
  return ($now < START_TIME) || ($now >= END_TIME);
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
  }

  $user_id = (int)($_POST['user_id'] ?? 0);
  $lat     = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
  $lng     = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
  // ALASAN MASUK (pakai kolom 'alasan' di tabel)
  $alasan  = isset($_POST['alasan']) ? trim((string)$_POST['alasan']) : '';

  if ($user_id <= 0 || $lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'user_id, lat, lng wajib']); exit;
  }

  // --- upload foto (opsional) ---

      $MAX_BYTES = 500 * 1024; // 500KB
if (!empty($_FILES['foto']['tmp_name'])) {
  if (($_FILES['foto']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Upload error']); exit;
  }
  if (($_FILES['foto']['size'] ?? 0) > $MAX_BYTES) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'File terlalu besar (maks 500KB).']); exit;
  }
}

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
  //    Tambahkan kolom 'alasan' pada INSERT awal.
  $ins = $conn->prepare("
    INSERT IGNORE INTO absen (user_id, tanggal, jam_masuk, masuk_lat, masuk_lng, foto_masuk, alasan)
    VALUES (?, CURDATE(), CURTIME(), ?, ?, ?, NULLIF(?, ''))
  ");
  // i d d s s
  $ins->bind_param("iddss", $user_id, $lat, $lng, $fotoPath, $alasan);
  $ins->execute();

  // 2) isi kolom yang masih NULL saja (termasuk 'alasan')
    $upd = $conn->prepare("
      UPDATE absen
        SET jam_masuk  = IFNULL(jam_masuk, CURTIME()),
            masuk_lat  = IFNULL(masuk_lat,  ?),
            masuk_lng  = IFNULL(masuk_lng,  ?),
            foto_masuk = IFNULL(foto_masuk, ?),
            alasan     = CASE 
                            WHEN COALESCE(status,'HADIR') IN ('IZIN','SAKIT')
                              THEN IFNULL(alasan, NULLIF(?, ''))
                            ELSE NULL
                          END
      WHERE user_id=? AND tanggal=CURDATE()
      LIMIT 1
    ");
    $upd->bind_param("ddssi", $lat, $lng, $fotoPath, $alasan, $user_id);
    $upd->execute();


  // kirim balik row hari ini
  $sel = $conn->prepare("SELECT * FROM absen WHERE user_id=? AND tanggal=CURDATE() LIMIT 1");
  $sel->bind_param("i", $user_id);
  $sel->execute();
  $row = $sel->get_result()->fetch_assoc();

  // panggil lembur/upsert (non-blocking)
  try {
    $payload = json_encode([
      'user_id' => $user_id,
      'tanggal' => date('Y-m-d'),
      'alasan'  => ($alasan !== '' ? $alasan : null),
      'action'  => 'masuk',
    ]);

    if (function_exists('curl_init')) {
      // NOTE: kalau butuh auto-host seperti checkout, bisa ganti base host dinamis
      $ch = curl_init("http://localhost/penggajian/api/lembur/upsert.php");
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
      ]);
      curl_exec($ch);
      curl_close($ch);
    } else {
      $ctx = stream_context_create([
        'http' => [
          'method'  => 'POST',
          'header'  => "Content-Type: application/json\r\n",
          'content' => $payload,
          'timeout' => 5,
        ],
      ]);
      @file_get_contents("http://localhost/penggajian/api/lembur/upsert.php", false, $ctx);
    }
  } catch (\Throwable $e) {
    // jangan gagalkan check-in karena lembur gagal
  }

  echo json_encode(['success'=>true,'message'=>'Check-in OK','data'=>$row]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
