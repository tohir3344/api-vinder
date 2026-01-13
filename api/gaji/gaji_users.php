<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../Koneksi.php';

/** ===== Ambil koneksi dari Koneksi.php ===== */
$mysqli = null;
if (isset($conn) && $conn instanceof mysqli) {
  $mysqli = $conn;
} elseif (isset($db) && $db instanceof mysqli) {
  $mysqli = $db;
}

if (!$mysqli) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "Koneksi DB tidak ditemukan dari Koneksi.php"
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // ===== Charset: fallback kalau utf8mb4 nggak didukung =====
  try {
    $mysqli->set_charset('utf8mb4');
  } catch (Throwable $e) {
    // fallback ke utf8 (lebih tua, tapi aman di hosting jadul)
    $mysqli->set_charset('utf8');
  }

  // ===== Deteksi kolom users secara dinamis =====
  $cols = [];
  $res = $mysqli->query("SHOW COLUMNS FROM `users`");
  while ($c = $res->fetch_assoc()) {
    $cols[strtolower($c['Field'])] = true;
  }

  $idCandidates   = ['id', 'id_user', 'user_id'];
  $nameCandidates = ['nama', 'name', 'nama_lengkap', 'full_name', 'username', 'email'];

  // Kolom ID
  $idCol = null;
  foreach ($idCandidates as $c) {
    if (isset($cols[$c])) { $idCol = $c; break; }
  }
  if (!$idCol) {
    http_response_code(500);
    echo json_encode([
      "success" => false,
      "message" => "Tabel users tidak memiliki kolom id (id/id_user/user_id)"
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Kolom nama yang ada
  $existingNameCols = [];
  foreach ($nameCandidates as $c) {
    if (isset($cols[$c])) $existingNameCols[] = $c;
  }

  // Kolom gaji (opsional)
  $gajiCol = null;
  if (isset($cols['gaji'])) {
    $gajiCol = 'gaji';
  } elseif (isset($cols['salary'])) {
    $gajiCol = 'salary';
  }

  // SELECT hanya kolom yang ada
  $selectCols = [$idCol];
  foreach ($existingNameCols as $c) $selectCols[] = $c;
  if ($gajiCol) $selectCols[] = $gajiCol;

  // <<<— DI SINI: ganti arrow function fn() => dengan function biasa
  $wrapped = array_map(function ($x) {
    return "`" . $x . "`";
  }, $selectCols);

  $sel = implode(',', $wrapped);

  $rs  = $mysqli->query("SELECT $sel FROM `users` ORDER BY `$idCol` ASC LIMIT 1000");

  $out = [];
  while ($row = $rs->fetch_assoc()) {
    $id = (int) $row[$idCol];

    $nama = null;
    foreach ($nameCandidates as $c) {
      if (isset($row[$c]) && trim((string)$row[$c]) !== '') {
        $nama = (string)$row[$c];
        break;
      }
    }
    if ($nama === null) $nama = 'User#'.$id;

    $gaji = 0;
    if ($gajiCol && array_key_exists($gajiCol, $row)
        && $row[$gajiCol] !== null && $row[$gajiCol] !== '') {
      $gaji = (int)$row[$gajiCol];
    }

    $out[] = [
      "id"   => $id,
      "nama" => $nama,
      "gaji" => $gaji,
    ];
  }

  echo json_encode(["success" => true, "data" => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  // Biar nggak cuma HTTP ERROR 500 generik, tapi kirim JSON yang bisa dibaca di app
  http_response_code(500);
  error_log("gaji_users.php ERROR: " . $e->getMessage());
  echo json_encode([
    "success" => false,
    "message" => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}
