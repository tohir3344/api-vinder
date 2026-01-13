<?php
// api/auth/get_user.php
declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

require_once __DIR__ . '/../Koneksi.php';

/**
 * Base URL helper (ingat file ini ada di /api/auth/get_user.php)
 * Jadi base_url() hasilnya: http(s)://host/penggajian/api/
 * (tergantung root project kamu)
 */
function base_url(): string {
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

  // Misal SCRIPT_NAME = /penggajian/api/auth/get_user.php
  $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\'); // /penggajian/api/auth
  $apiPath   = rtrim(dirname($scriptDir), '/\\');                    // /penggajian/api

  return $scheme . '://' . $host . $apiPath . '/'; // http(s)://host/penggajian/api/
}

try {
  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($id <= 0) {
    throw new Exception('id wajib');
  }

  $sql = "
    SELECT
      u.id,
      u.username,
      u.nama_lengkap,
      u.tempat_lahir,
      u.tanggal_lahir,
      u.no_telepon,
      u.alamat,
      u.masa_kerja,
      u.foto,
      u.email,
      u.role,
      u.created_at,
      u.tanggal_masuk   -- ⭐ tanggal mulai kerja (boleh NULL)
    FROM users u
    WHERE u.id = ?
    LIMIT 1
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new Exception('Gagal prepare statement');
  }

  $stmt->bind_param("i", $id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();

  if (!$row) {
    echo json_encode([
      'success' => false,
      'message' => 'User tidak ditemukan',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  // ===== Normalisasi foto =====
  // - Kalau sudah http/https/file:// → pakai apa adanya
  // - Kalau mulai dengan "uploads/" → anggap sudah mengandung foldernya, tinggal tempel base_url()
  // - Kalau hanya nama file → tempel ke uploads/foto/{nama_file}
  if (!empty($row['foto'])) {
    $foto = trim((string)$row['foto']);

    // sudah absolute URL
    if (preg_match('~^https?://~i', $foto) || strpos($foto, 'file://') === 0) {
      // biarkan
      $row['foto'] = $foto;
    } else {
      // relative path / nama file
      $fotoClean = ltrim($foto, '/');

      if (stripos($fotoClean, 'uploads/') === 0) {
        // contoh: uploads/foto_user/usr_xxx.jpeg
        $row['foto'] = base_url() . $fotoClean;
      } else {
        // contoh: usr_xxx.jpeg → simpan di uploads/foto/usr_xxx.jpeg
        $row['foto'] = base_url() . 'uploads/foto/' . $fotoClean;
      }
    }
  } else {
    $row['foto'] = null;
  }

  // ===== Normalisasi masa_kerja =====
  $mk = trim((string)($row['masa_kerja'] ?? ''));
  if ($mk === '') {
    $mk = '0 tahun 0 bulan 0 hari';
  }
  $row['masa_kerja'] = $mk;

  echo json_encode([
    'success' => true,
    'data'    => $row,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode([
    'success' => false,
    'message' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
