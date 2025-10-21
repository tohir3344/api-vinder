<?php
// api/auth/get_user.php
declare(strict_types=1);
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . '/../Koneksi.php';

/* Base URL helper untuk foto */
function base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // .../api/auth
  // naik satu level ke /api
  $apiPath = rtrim(dirname($path), '/\\');
  return $scheme.'://'.$host.$apiPath.'/';
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
    echo json_encode(['success' => false, 'message' => 'User tidak ditemukan'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  // Jika "foto" adalah nama file, ubah menjadi URL lengkap: {base}/uploads/foto/{nama_file}
  if (!empty($row['foto']) && !preg_match('~^https?://~i', $row['foto'])) {
    $row['foto'] = base_url().'uploads/foto/'.$row['foto'];
  } elseif (empty($row['foto'])) {
    $row['foto'] = null;
  }

  echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
