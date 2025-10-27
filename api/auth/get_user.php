<?php
// api/auth/get_user.php
declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';

/** Base URL helper untuk foto (tahan di subfolder /api) */
function base_url(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

  // /api/auth → /api
  $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
  $apiPath   = rtrim(dirname($scriptDir), '/\\'); // naik satu level
  return $scheme . '://' . $host . $apiPath . '/';
}

try {
  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($id <= 0) throw new Exception('id wajib');

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
      u.created_at
    FROM users u
    WHERE u.id = ?
    LIMIT 1
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();

  if (!$row) {
    echo json_encode(['success' => false, 'message' => 'User tidak ditemukan'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  // Foto → URL absolut jika yang dikirim hanyalah nama file
  if (!empty($row['foto']) && !preg_match('~^https?://~i', (string)$row['foto'])) {
    $row['foto'] = base_url() . 'uploads/foto/' . ltrim((string)$row['foto'], '/');
  } elseif (empty($row['foto'])) {
    $row['foto'] = null;
  }

  // Masa kerja diambil apa adanya dari kolom users.masa_kerja.
  // Jika kosong/null, fallback aman ke "0 tahun 0 bulan 0 hari".
  $mk = trim((string)($row['masa_kerja'] ?? ''));
  $row['masa_kerja'] = ($mk !== '') ? $mk : '0 tahun 0 bulan 0 hari';

  echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
