<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php'; 
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ==== Helpers base URL ==== */
function base_api(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); 
  $apiDir = rtrim(dirname($path), '/\\');                   
  return $scheme.'://'.$host.$apiDir.'/';
}

function base_app(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); 
  $root   = rtrim(dirname($path), '/\\');                   
  $appDir = rtrim(dirname($root), '/\\');                   
  return $scheme.'://'.$host.$appDir.'/';
}

try {
  /* ====== BASE URL publik (auto) ====== */
  $BASE_API = base_api();
  $BASE_APP = base_app();

  /* ====== Utils URL ====== */
  function join_url(string $base, string $path): string {
    return rtrim($base, '/') . '/' . ltrim($path, '/');
  }

  // Logic Foto Masuk
  function build_url_masuk(string $baseApi, ?string $val): ?string {
    if (!$val) return null;
    if (preg_match('#^https?://#i', $val)) return $val;
    $v = ltrim($val, '/');
    // Paksa path ke uploads/absen/
    $v = preg_replace('#^(api/)?uploads/absen/#i', 'uploads/absen/', $v);
    if (!preg_match('#^uploads/absen/#i', $v)) {
      $v = preg_replace('#^uploads/#i', '', $v);
      $v = 'uploads/absen/' . $v;
    }
    return join_url($baseApi, $v);
  }

  // Logic Foto Keluar
  function build_url_keluar(string $baseApp, ?string $val): ?string {
    if (!$val) return null;
    if (preg_match('#^https?://#i', $val)) return $val;
    $v = ltrim($val, '/');
    $v = preg_replace('#^api/#i', '', $v);
    $v = ltrim($v, '/');
    // Paksa path ke uploads/absen/
    if (preg_match('#^uploads/absen/#i', $v)) {
        // ok
    } elseif (preg_match('#^uploads/#i', $v)) {
        $v = preg_replace('#^uploads/#i', 'uploads/absen/', $v);
    } else {
        $v = 'uploads/absen/' . $v;
    }
    return join_url($baseApp, $v);
  }

  /* ==================== GET LIST GALERI ==================== */
  header("Content-Type: application/json; charset=utf-8");

  $laporanMasuk = [];
  $laporanKeluar = [];

  // 🔥 QUERY SAKTI: LANGSUNG JOIN KE TABEL 'users' 🔥
  // Kita ambil kolom 'username' dan 'nama_lengkap' dari tabel users.
  
  // 1. DATA MASUK
  $sqlMasuk  = "
    SELECT 
        a.id, 
        a.user_id, 
        a.tanggal, 
        a.jam_masuk, 
        a.foto_masuk AS foto_raw,
        u.username,      -- Ambil Username
        u.nama_lengkap   -- Ambil Nama Lengkap (buat cadangan)
    FROM absen a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.foto_masuk IS NOT NULL AND a.foto_masuk <> '' 
    ORDER BY a.id DESC
  ";

  if ($res = $conn->query($sqlMasuk)) {
    while ($row = $res->fetch_assoc()) {
      $row['foto_url'] = build_url_masuk($BASE_API, $row['foto_raw']);
      
      // 🔥 PRIORITAS UTAMA: USERNAME 🔥
      // Kalau username ada, pake username. Kalau kosong, pake nama_lengkap. Kalau kosong juga, pake ID.
      $displayName = !empty($row['username']) ? $row['username'] : (!empty($row['nama_lengkap']) ? $row['nama_lengkap'] : 'ID '.$row['user_id']);
      
      // Kita timpa kolom 'nama' biar frontend gak perlu diubah logic-nya
      $row['nama'] = $displayName;

      unset($row['foto_raw']); // Bersihin data mentah biar json enteng
      $laporanMasuk[] = $row;
    }
  }

  // 2. DATA KELUAR
  $sqlKeluar = "
    SELECT 
        a.id, 
        a.user_id, 
        a.tanggal, 
        a.jam_keluar, 
        a.foto_keluar AS foto_raw,
        u.username, 
        u.nama_lengkap
    FROM absen a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.foto_keluar IS NOT NULL AND a.foto_keluar <> '' 
    ORDER BY a.id DESC
  ";

  if ($res = $conn->query($sqlKeluar)) {
    while ($row = $res->fetch_assoc()) {
      $row['foto_url'] = build_url_keluar($BASE_API, $row['foto_raw']);
      
      // 🔥 PRIORITAS UTAMA: USERNAME 🔥
      $displayName = !empty($row['username']) ? $row['username'] : (!empty($row['nama_lengkap']) ? $row['nama_lengkap'] : 'ID '.$row['user_id']);
      
      $row['nama'] = $displayName;

      unset($row['foto_raw']);
      $laporanKeluar[] = $row;
    }
  }

  echo json_encode([
    'laporan_masuk'  => $laporanMasuk,
    'laporan_keluar' => $laporanKeluar,
    'meta' => ['api_base' => $BASE_API, 'app_base' => $BASE_APP]
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode([
    'success' => false,
    'error'   => 'Server error: ' . $e->getMessage()
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
?>