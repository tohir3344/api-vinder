<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../Koneksi.php';

$mysqli = null;
if (isset($conn) && $conn instanceof mysqli) $mysqli = $conn;
elseif (isset($db) && $db instanceof mysqli)  $mysqli = $db;

if (!$mysqli) {
  http_response_code(500);
  echo json_encode(["success"=>false,"message"=>"Koneksi DB tidak ditemukan dari Koneksi.php"]);
  exit;
}
$mysqli->set_charset('utf8mb4');

function qs(string $k, ?string $def=null): ?string { return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $def; }

/** Deteksi kolom id & nama users */
function users_columns(mysqli $db): array {
  $cols = [];
  $r = $db->query("SHOW COLUMNS FROM `users`");
  while ($c = $r->fetch_assoc()) $cols[strtolower($c['Field'])] = true;

  $idCandidates   = ['id','id_user','user_id'];
  $nameCandidates = ['nama','name','nama_lengkap','full_name','username','email'];

  $idCol = null;
  foreach ($idCandidates as $c) if (isset($cols[$c])) { $idCol = $c; break; }
  if (!$idCol) throw new RuntimeException("Tabel users tidak punya kolom id (id/id_user/user_id)");

  $nameCol = null;
  foreach ($nameCandidates as $c) if (isset($cols[$c])) { $nameCol = $c; break; }
  if (!$nameCol) $nameCol = $idCol; // fallback

  return [$idCol, $nameCol];
}

try {
  [$uidCol, $nameCol] = users_columns($mysqli);

  $user_id = (int) (qs('user_id') ?? 0);
  $start   = qs('start');
  $end     = qs('end');
  $limit   = max(1, min(1000, (int) (qs('limit') ?? 100)));
  $page    = max(1, (int) (qs('page') ?? 1));
  $offset  = ($page - 1) * $limit;

  $wheres = [];
  $params = [];
  $types  = "";

  if ($user_id > 0) { $wheres[] = "gr.user_id = ?"; $params[] = $user_id; $types .= "i"; }
  if ($start && $end) { $wheres[] = "DATE(gr.periode_start) >= ? AND DATE(gr.periode_end) <= ?"; $params[] = $start; $params[] = $end; $types .= "ss"; }
  $whereSql = $wheres ? ("WHERE ".implode(" AND ", $wheres)) : "";

  $sql = "
    SELECT
      gr.*,
      u.`$nameCol` AS nama
    FROM gaji_run gr
    LEFT JOIN users u ON u.`$uidCol` = gr.user_id
    $whereSql
    ORDER BY gr.periode_end DESC, gr.id DESC
    LIMIT ? OFFSET ?
  ";
  $types .= "ii";
  $params[] = $limit;
  $params[] = $offset;

  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();

  $rows = [];
  while ($row = $res->fetch_assoc()) {
    // Normalisasi angka & alias kompat
    foreach ([
      'id','user_id','hadir_minggu','lembur_menit','lembur_rp',
      'gaji_pokok_rp','angsuran_rp','kerajinan_rp','kebersihan_rp','ibadah_rp',
      'thr_rp','bonus_akhir_tahun_rp','others_total_rp','total_gaji_rp'
    ] as $k) {
      if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (int)$row[$k];
    }

    if (!isset($row['nama']) || $row['nama'] === null || $row['nama'] === '') {
      $row['nama'] = 'User#'.$row['user_id'];
    }

    // Alias sementara bila kolom baru belum ada
    if (!isset($row['thr_rp']))                 $row['thr_rp']               = (int)($row['kerajinan_rp']  ?? 0);
    if (!isset($row['bonus_akhir_tahun_rp']))   $row['bonus_akhir_tahun_rp'] = (int)($row['kebersihan_rp'] ?? 0);
    if (!isset($row['others_total_rp']))        $row['others_total_rp']      = (int)($row['ibadah_rp']     ?? 0);

    $rows[] = $row;
  }

  echo json_encode(["success"=>true, "data"=>$rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["success"=>false,"message"=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
