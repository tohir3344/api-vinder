<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php'; // $conn dari sini

/* ===== DEBUG: ?debug=1 ===== */
if (isset($_GET['debug'])) {
  $dbRow = $conn->query("SELECT DATABASE() AS db")->fetch_assoc();
  $db    = $dbRow['db'] ?? null;

  $TABLE_LEMBUR = 'lembur';
  $cols = [];
  if ($res = $conn->query("SHOW COLUMNS FROM `$TABLE_LEMBUR`")) {
    while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
  } else {
    $cols[] = "<SHOW COLUMNS gagal: " . $conn->error . ">";
  }

  echo json_encode([
    'success' => true,
    'debug'   => [
      'database' => $db,
      'table'    => $TABLE_LEMBUR,
      'columns'  => $cols,
    ],
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
  }

  $user_id = (int)($_POST['user_id'] ?? 0);

  // dukung dua nama field: lat/lng ATAU keluar_lat/keluar_lng
  $lat = isset($_POST['keluar_lat']) ? (float)$_POST['keluar_lat']
       : (isset($_POST['lat']) ? (float)$_POST['lat'] : null);
  $lng = isset($_POST['keluar_lng']) ? (float)$_POST['keluar_lng']
       : (isset($_POST['lng']) ? (float)$_POST['lng'] : null);

  // alasan khusus keluar (optional, TIDAK disimpan di tabel absen)
  $alasan_keluar = isset($_POST['alasan_keluar'])
      ? trim((string)$_POST['alasan_keluar'])
      : '';

  if ($user_id <= 0 || $lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'user_id, lat, lng wajib']); exit;
  }

  // --- upload foto (opsional; sama kayak check-in) ---

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

    $fname = 'keluar_' . $user_id . '_' . date('Ymd_His') . '.jpg';
    $dest  = $dir . '/' . $fname;
    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
      http_response_code(500);
      echo json_encode(['success'=>false,'message'=>'Upload foto gagal']); exit;
    }
    $fotoPath = 'uploads/absen/' . $fname;
  }

  // 1) pastikan ada baris hari ini (kalau belum, buat; kalau sudah, IGNORE)
  $ins = $conn->prepare("
    INSERT IGNORE INTO absen (user_id, tanggal, jam_keluar, keluar_lat, keluar_lng, foto_keluar)
    VALUES (?, CURDATE(), CURTIME(), ?, ?, ?)
  ");
  $ins->bind_param("idds", $user_id, $lat, $lng, $fotoPath);
  $ins->execute();
  $ins->close();

  // 2) isi kolom KELUAR yang masih NULL saja
  $upd = $conn->prepare("
    UPDATE absen
       SET jam_keluar   = IFNULL(jam_keluar, CURTIME()),
           keluar_lat   = IFNULL(keluar_lat,  ?),
           keluar_lng   = IFNULL(keluar_lng,  ?),
           foto_keluar  = IFNULL(foto_keluar, ?)
     WHERE user_id=? AND tanggal=CURDATE()
     LIMIT 1
  ");
  $upd->bind_param("ddsi", $lat, $lng, $fotoPath, $user_id);
  $upd->execute();
  $upd->close();

  // ambil row hari ini
  $sel = $conn->prepare("SELECT * FROM absen WHERE user_id=? AND tanggal=CURDATE() LIMIT 1");
  $sel->bind_param("i", $user_id);
  $sel->execute();
  $row = $sel->get_result()->fetch_assoc();
  $sel->close();

  // siapkan field jam & tanggal untuk upsert + response
  $tanggal    = $row['tanggal']    ?? date('Y-m-d');
  $jam_masuk  = $row['jam_masuk']  ?? null;
  $jam_keluar = $row['jam_keluar'] ?? null;

  // --- bangun public URL foto keluar pakai host/IP yang dipakai klien (dinamis) ---
  $foto_url = null;
  if (!empty($row['foto_keluar'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'); // include port jika ada
    // script: /penggajian/api/absen/checkout.php -> app root: /penggajian
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/'); // .../api/absen
    $appRoot   = dirname($scriptDir);                     // .../api
    $appRoot   = dirname($appRoot);                       // .../penggajian
    $publicBase = rtrim("$scheme://$host$appRoot", '/');
    $foto_url = $publicBase . '/' . ltrim($row['foto_keluar'], '/');
  }

  // --- panggil lembur/upsert (non-blocking: error diabaikan) ---
  try {
    // build base URL mengikuti host/port request, gak hardcode localhost
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/'); // .../api/absen
    $apiRoot   = dirname($scriptDir);                     // .../api
    $publicBase = rtrim("$scheme://$host$apiRoot", '/');  // .../penggajian/api
    $upsertUrl  = $publicBase . '/lembur/upsert.php';

    $payload = json_encode([
      'user_id'       => $user_id,
      'tanggal'       => $tanggal,
      'jam_masuk'     => $jam_masuk,
      'jam_keluar'    => $jam_keluar,
      'alasan_keluar' => ($alasan_keluar !== '' ? $alasan_keluar : null),
      'action'        => 'keluar',
    ]);

    if (function_exists('curl_init')) {
      $ch = curl_init($upsertUrl);
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
      @file_get_contents($upsertUrl, false, $ctx);
    }
  } catch (\Throwable $e) {
    // jangan gagalkan checkout
  }

  echo json_encode([
    'success'     => true,
    'message'     => 'Check-out OK',
    'tanggal'     => $tanggal,     // <-- top-level untuk FE
    'jam_masuk'   => $jam_masuk,   // <--
    'jam_keluar'  => $jam_keluar,  // <--
    'data'        => $row,
    'foto_url'    => $foto_url,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
