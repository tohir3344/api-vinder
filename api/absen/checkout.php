<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  // ===== DB =====
  $DB_NAME = "penggajian_db";
  $conn = new mysqli("localhost", "root", "", $DB_NAME);
  $conn->set_charset('utf8mb4');

  // ===== Input =====
  $userId  = $_POST['user_id']   ?? null;
  $absenId = $_POST['id_absen']  ?? null; // opsional
  $tanggal = $_POST['tanggal']   ?? null; // opsional (YYYY-MM-DD)
  // file: $_FILES['foto']

  if (!$userId) {
    http_response_code(400);
    echo json_encode(['error' => "Param 'user_id' wajib ada"]); exit;
  }
  if (!isset($_FILES['foto']) || !is_uploaded_file($_FILES['foto']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => "File 'foto' wajib (multipart/form-data)"]); exit;
  }

  // ===== Waktu lokal (Asia/Jakarta) =====
  $tz = new DateTimeZone('Asia/Jakarta');
  $now = new DateTime('now', $tz);
  if (!$tanggal) $tanggal = $now->format('Y-m-d');
  $jamKeluar = $now->format('H:i:s');

  // ===== Folder tujuan KELUAR: /penggajian/uploads/ =====
  // File ini diasumsikan berada di /penggajian/api/absen/checkout.php
  // Jadi root project = ../../ dari file ini
  $projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
  $uploadDir   = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;

  if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
    throw new RuntimeException("Gagal membuat folder upload di: $uploadDir");
  }

  // ===== Simpan file keluar dengan prefix out_ =====
  $allow = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic','image/avif'=>'avif'];
  $mime = @mime_content_type($_FILES['foto']['tmp_name']) ?: '';
  $ext  = $allow[$mime] ?? strtolower(pathinfo($_FILES['foto']['name'] ?? 'jpg', PATHINFO_EXTENSION) ?: 'jpg');

  $fname = 'out_' . date('Ymd_His') . '_' . mt_rand(1000,9999) . '.' . $ext;
  $dest  = $uploadDir . $fname;

  if (!move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
    http_response_code(400);
    echo json_encode(['error' => 'Gagal menyimpan file upload']); exit;
  }

  // ===== Cari/siapkan baris absen yang akan diupdate =====
  $absenRowId = null;

  // 1) jika id_absen dikirim & valid
  if ($absenId && ctype_digit((string)$absenId)) {
    $st = $conn->prepare("SELECT id FROM absen WHERE id=?");
    $st->bind_param('i', $absenId);
    $st->execute(); $st->bind_result($foundId);
    if ($st->fetch()) $absenRowId = (int)$foundId;
    $st->close();
  }

  // 2) fallback: cari berdasarkan (user_id, tanggal)
  if (!$absenRowId) {
    $st = $conn->prepare("SELECT id FROM absen WHERE user_id=? AND tanggal=? LIMIT 1");
    $st->bind_param('is', $userId, $tanggal);
    $st->execute(); $st->bind_result($foundId);
    if ($st->fetch()) $absenRowId = (int)$foundId;
    $st->close();
  }

  // 3) kalau belum ada, buat baris baru (supaya selalu bisa keluar)
  if (!$absenRowId) {
    $st = $conn->prepare("INSERT INTO absen (user_id, tanggal) VALUES (?, ?)");
    $st->bind_param('is', $userId, $tanggal);
    $st->execute();
    $absenRowId = $st->insert_id;
    $st->close();
  }

  // ===== Update kolom foto_keluar + jam_keluar (BACA: bukan foto_masuk) =====
  $st = $conn->prepare("UPDATE absen SET foto_keluar=?, jam_keluar=? WHERE id=?");
  $st->bind_param('ssi', $fname, $jamKeluar, $absenRowId);
  $st->execute();
  $st->close();

  // ===== URL publik konfirmasi (buat dicek cepat dari app/browser) =====
  $BASE_APP = "http://192.168.1.11/penggajian/"; // root publik
  $publicUrl = rtrim($BASE_APP, '/') . '/uploads/' . $fname;

  echo json_encode([
    'success'     => true,
    'mode'        => 'keluar',
    'id_absen'    => $absenRowId,
    'user_id'     => (int)$userId,
    'tanggal'     => $tanggal,
    'jam_keluar'  => $jamKeluar,
    'file'        => $fname,
    'target_dir'  => $uploadDir,
    'public_url'  => $publicUrl
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Server error: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
}