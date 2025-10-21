<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 0);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ==== Helpers base URL ==== */
function base_api(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // .../api/galeri
  $apiDir = rtrim(dirname($path), '/\\');                   // .../api
  return $scheme.'://'.$host.$apiDir.'/';
}
function base_app(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // .../api/galeri
  $root   = rtrim(dirname($path), '/\\');                   // .../api
  $appDir = rtrim(dirname($root), '/\\');                   // ... (folder proyek, sejajar /api)
  return $scheme.'://'.$host.$appDir.'/';
}

try {
  /* ====== DB ====== */
  $DB_NAME = "penggajian_db";
  $conn = new mysqli("localhost", "root", "", $DB_NAME);
  $conn->set_charset('utf8mb4');

  /* ====== BASE URL publik (auto) ====== */
  $BASE_API = base_api(); // contoh: http://192.168.1.x/penggajian/api/
  $BASE_APP = base_app(); // contoh: http://192.168.1.x/penggajian/

  /* ====== Utils URL ====== */
  function join_url(string $base, string $path): string {
    return rtrim($base, '/') . '/' . ltrim($path, '/');
  }
  function build_url_masuk(string $baseApi, ?string $val): ?string {
    if (!$val) return null;
    if (preg_match('#^https?://#i', $val)) return $val;
    $v = ltrim($val, '/');
    $v = preg_replace('#^(api/)?uploads/absen/#i', 'uploads/absen/', $v);
    if (!preg_match('#^uploads/absen/#i', $v)) {
      $v = preg_replace('#^uploads/#i', '', $v);
      $v = 'uploads/absen/' . $v;
    }
    return join_url($baseApi, $v);
  }
  function build_url_keluar(string $baseApp, ?string $val): ?string {
    if (!$val) return null;
    if (preg_match('#^https?://#i', $val)) return $val;
    $v = ltrim($val, '/');
    $v = preg_replace('#^(api/)?uploads/absen/#i', 'uploads/', $v);
    $v = preg_replace('#^api/uploads/#i', 'uploads/', $v);
    if (!preg_match('#^uploads/#i', $v)) $v = 'uploads/' . $v;
    return join_url($baseApp, $v);
  }

  /* ====== Helpers schema ====== */
  function table_exists(mysqli $c, string $db, string $t): bool {
    $sql = "SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA='{$c->real_escape_string($db)}'
              AND TABLE_NAME='{$c->real_escape_string($t)}' LIMIT 1";
    $res = $c->query($sql); return (bool)$res->fetch_row();
  }
  function column_exists(mysqli $c, string $db, string $t, string $col): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='{$c->real_escape_string($db)}'
              AND TABLE_NAME='{$c->real_escape_string($t)}'
              AND COLUMN_NAME='{$c->real_escape_string($col)}' LIMIT 1";
    $res = $c->query($sql); return (bool)$res->fetch_row();
  }

  /* ====== Deteksi tabel users untuk nama ====== */
  $userTable = null;
  foreach (['users','user'] as $t) if (table_exists($conn, $DB_NAME, $t)) { $userTable = $t; break; }
  function get_user_name(mysqli $c, string $db, ?string $tbl, $userId): ?string {
    if (!$tbl) return null;
    $idCol = null; foreach (['id','id_user'] as $x) if (column_exists($c, $db, $tbl, $x)) { $idCol = $x; break; }
    if (!$idCol) return null;
    $pieces = [];
    foreach (['nama','nama_lengkap','fullname','full_name','name','username'] as $n)
      if (column_exists($c,$db,$tbl,$n)) $pieces[] = "NULLIF(TRIM(`$n`),'')";
    if (!$pieces) return null;

    $select = "COALESCE(".implode(",", $pieces).") AS nx";
    $uid = $c->real_escape_string((string)$userId);
    $tblSafe = "`" . str_replace("`","``",$tbl) . "`";
    $idColSafe = "`" . str_replace("`","``",$idCol) . "`";
    $sql = "SELECT $select FROM $tblSafe WHERE $idColSafe='$uid' LIMIT 1";
    $res = $c->query($sql);
    if ($row = $res->fetch_assoc()) {
      $nx = trim((string)($row['nx'] ?? ''));
      return $nx ?: null;
    }
    return null;
  }

  /* ==================== GET list galeri ==================== */
  header("Content-Type: application/json; charset=utf-8");

  $laporanMasuk = [];
  $laporanKeluar = [];

  $sqlMasuk  = "SELECT id, user_id, tanggal, jam_masuk,  foto_masuk  AS foto_raw
                FROM absen WHERE foto_masuk  IS NOT NULL AND foto_masuk  <> '' ORDER BY id DESC";
  $sqlKeluar = "SELECT id, user_id, tanggal, jam_keluar, foto_keluar AS foto_raw
                FROM absen WHERE foto_keluar IS NOT NULL AND foto_keluar <> '' ORDER BY id DESC";

  if ($res = $conn->query($sqlMasuk)) {
    while ($row = $res->fetch_assoc()) {
      $row['foto_url'] = build_url_masuk($BASE_API, $row['foto_raw']);   // /api/uploads/absen/...
      $row['nama'] = get_user_name($conn, $DB_NAME, $userTable, $row['user_id']) ?? ('ID '.$row['user_id']);
      unset($row['foto_raw']);
      $laporanMasuk[] = $row;
    }
  }
  if ($res = $conn->query($sqlKeluar)) {
    while ($row = $res->fetch_assoc()) {
      $row['foto_url'] = build_url_keluar($BASE_APP, $row['foto_raw']);  // /uploads/...
      $row['nama'] = get_user_name($conn, $DB_NAME, $userTable, $row['user_id']) ?? ('ID '.$row['user_id']);
      unset($row['foto_raw']);
      $laporanKeluar[] = $row;
    }
  }

  echo json_encode([
    'laporan_masuk'  => $laporanMasuk,
    'laporan_keluar' => $laporanKeluar,
    'meta' => ['api_base' => $BASE_API, 'app_base' => $BASE_APP]
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode(['error' => 'Server error: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
