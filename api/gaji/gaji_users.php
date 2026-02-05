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
  // ===== Charset fallback =====
  try {
    $mysqli->set_charset('utf8mb4');
  } catch (Throwable $e) {
    $mysqli->set_charset('utf8');
  }

  // ===== Deteksi kolom users secara dinamis =====
  $cols = [];
  $res = $mysqli->query("SHOW COLUMNS FROM `users`");
  while ($c = $res->fetch_assoc()) {
    $cols[strtolower($c['Field'])] = true;
  }

  // Kandidat nama kolom
  $idCandidates   = ['id', 'id_user', 'user_id'];
  $nameCandidates = ['nama', 'name', 'nama_lengkap', 'full_name', 'username', 'email'];
  // 🔥 UPDATE: Tambah kandidat untuk kolom POSISI/JABATAN
  $roleCandidates = ['posisi', 'role', 'jabatan', 'level', 'tipe_user', 'hak_akses'];

  // 1. Cari Kolom ID
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

  // 2. Cari Kolom Nama
  $existingNameCols = [];
  foreach ($nameCandidates as $c) {
    if (isset($cols[$c])) $existingNameCols[] = $c;
  }

  // 3. Cari Kolom Gaji
  $gajiCol = null;
  if (isset($cols['gaji'])) {
    $gajiCol = 'gaji';
  } elseif (isset($cols['salary'])) {
    $gajiCol = 'salary';
  }

  // 4. 🔥 UPDATE: Cari Kolom Posisi/Role
  $roleCol = null;
  foreach ($roleCandidates as $c) {
    if (isset($cols[$c])) { $roleCol = $c; break; }
  }

  // ===== Susun Query SELECT =====
  $selectCols = [$idCol];
  foreach ($existingNameCols as $c) $selectCols[] = $c;
  if ($gajiCol) $selectCols[] = $gajiCol;
  if ($roleCol) $selectCols[] = $roleCol; // <-- Masukkan kolom posisi ke query

  // Bungkus nama kolom dengan backtick `
  $wrapped = array_map(function ($x) {
    return "`" . $x . "`";
  }, $selectCols);

  $sel = implode(',', $wrapped);

  // Jalankan Query
  $rs = $mysqli->query("SELECT $sel FROM `users` ORDER BY `$idCol` ASC LIMIT 1000");

  $out = [];
  while ($row = $rs->fetch_assoc()) {
    $id = (int) $row[$idCol];

    // Ambil Nama
    $nama = null;
    foreach ($nameCandidates as $c) {
      if (isset($row[$c]) && trim((string)$row[$c]) !== '') {
        $nama = (string)$row[$c];
        break;
      }
    }
    if ($nama === null) $nama = 'User#'.$id;

    // Ambil Gaji
    $gaji = 0;
    if ($gajiCol && isset($row[$gajiCol]) && $row[$gajiCol] !== '') {
      $gaji = (int)$row[$gajiCol];
    }

    // 🔥 UPDATE: Ambil Posisi
    $posisi = '';
    if ($roleCol && isset($row[$roleCol])) {
      $posisi = (string)$row[$roleCol];
    }

    $out[] = [
      "id"     => $id,
      "nama"   => $nama,
      "gaji"   => $gaji,
      "posisi" => $posisi // <-- Kirim data posisi ke React Native
    ];
  }

  echo json_encode(["success" => true, "data" => $out], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  error_log("gaji_users.php ERROR: " . $e->getMessage());
  echo json_encode([
    "success" => false,
    "message" => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}