<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../Koneksi.php';

// ===== Jam kerja (samakan dengan client & upsert) =====
const START_TIME = '07:40:00';
const END_TIME   = '13:00:00';
function isOutsideWorkingNow(): bool {
  $now = date('H:i:s');
  return ($now < START_TIME) || ($now >= END_TIME);
}

// mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
  }

  $user_id = (int)($_POST['user_id'] ?? 0);
  $lat = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
  $lng = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
  $alasan = isset($_POST['alasan']) ? trim((string)$_POST['alasan']) : '';

  if ($user_id <= 0 || $lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'user_id, lat, lng wajib']); exit;
  }

  // upload foto (opsional)
  $fotoPath = null;
  if (!empty($_FILES['foto']['tmp_name'])) {
    $dir = __DIR__ . '/../uploads/absen';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $fname = 'keluar_' . $user_id . '_' . date('Ymd_His') . '.jpg';
    $dest = $dir . '/' . $fname;
    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
      http_response_code(500);
      echo json_encode(['success'=>false,'message'=>'Upload foto gagal']); exit;
    }
    $fotoPath = 'uploads/absen/' . $fname;
  }

  // insert / update (unik di (user_id, tanggal))
  $sql = "
    INSERT INTO absen (user_id, tanggal, jam_keluar, keluar_lat, keluar_lng, foto_keluar)
    VALUES (?, CURDATE(), CURTIME(), ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      jam_keluar = COALESCE(jam_keluar, VALUES(jam_keluar)),
      keluar_lat = COALESCE(keluar_lat, VALUES(keluar_lat)),
      keluar_lng = COALESCE(keluar_lng, VALUES(keluar_lng)),
      foto_keluar = COALESCE(foto_keluar, VALUES(foto_keluar))
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("idds", $user_id, $lat, $lng, $fotoPath);
  $stmt->execute();

  // panggil lembur/upsert
  try {
    $payload = json_encode([
      'user_id' => $user_id,
      'tanggal' => date('Y-m-d'),
      'alasan'  => ($alasan !== '' ? $alasan : null),
    ]);

    if (function_exists('curl_init')) {
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
    // jangan gagalkan checkout kalau upsert gagal
  }

  echo json_encode([
    'success' => true,
    'message' => 'Check-out OK',
    'foto'    => $fotoPath,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
