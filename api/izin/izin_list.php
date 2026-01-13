<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

require_once __DIR__ . '/../Koneksi.php';

/* ============================================================
   HELPER
============================================================ */

/** Cek apakah kolom ada di tabel */
function has_column(mysqli $conn, string $table, string $column): bool {
  $res = $conn->query("SELECT DATABASE() AS db");
  if (!$res) return false;
  $row = $res->fetch_assoc();
  $db  = isset($row['db']) ? $row['db'] : '';

  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($db) . "'
      AND TABLE_NAME   = '" . $conn->real_escape_string($table) . "'
      AND COLUMN_NAME  = '" . $conn->real_escape_string($column) . "'
    LIMIT 1
  ";
  $res2 = $conn->query($sql);
  if (!$res2) return false;
  $ok = (bool)$res2->fetch_row();
  $res2->close();
  return $ok;
}

/** Baca JSON body; bila {action,data} ambil data-nya */
function read_json_body(): ?array {
  $raw = file_get_contents('php://input');
  if (!$raw) return null;
  $j = json_decode($raw, true);
  if (!is_array($j)) return null;
  if (isset($j['action']) && isset($j['data']) && is_array($j['data'])) {
    return $j['data'];
  }
  return $j;
}

/** Normalisasi string enum IZIN/SAKIT */
function norm_keterangan(?string $s): string {
  $x = strtoupper(trim((string)$s));
  return ($x === 'SAKIT') ? 'SAKIT' : 'IZIN';
}

/** Validasi Y-m-d */
function is_ymd(string $s): bool {
  $dt = DateTime::createFromFormat('Y-m-d', $s);
  return $dt && $dt->format('Y-m-d') === $s;
}

/* ============================================================
   DETEKSI KOLOM
============================================================ */

// tanggal mulai
$colMulai = null;
if (has_column($conn, 'izin', 'mulai')) {
  $colMulai = 'mulai';
} elseif (has_column($conn, 'izin', 'tanggal_mulai')) {
  $colMulai = 'tanggal_mulai';
}

// tanggal selesai
$colSelesai = null;
if (has_column($conn, 'izin', 'selesai')) {
  $colSelesai = 'selesai';
} elseif (has_column($conn, 'izin', 'tanggal_selesai')) {
  $colSelesai = 'tanggal_selesai';
}

if ($colMulai === null || $colSelesai === null) {
  echo json_encode([
    'success' => false,
    'message' => 'Kolom tanggal tidak ditemukan. Harus ada (mulai, selesai) atau (tanggal_mulai, tanggal_selesai).'
  ]);
  exit;
}

$colAlasan     = has_column($conn, 'izin', 'alasan') ? 'alasan' : null;
$colKeterangan = has_column($conn, 'izin', 'keterangan') ? 'keterangan' : null;
$colStatus     = has_column($conn, 'izin', 'status') ? 'status' : null;

if ($colStatus === null) {
  echo json_encode([
    'success' => false,
    'message' => 'Kolom status tidak ditemukan di tabel izin.'
  ]);
  exit;
}

$userNameCol = null;
if (has_column($conn, 'users', 'nama_lengkap')) {
  $userNameCol = 'nama_lengkap';
} elseif (has_column($conn, 'users', 'name')) {
  $userNameCol = 'name';
}

/* ============================================================
   POST — CREATE IZIN
============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $payload = read_json_body();
  if ($payload === null) {
    $payload = $_POST;
  }

  $user_id = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
  $ket     = norm_keterangan(isset($payload['keterangan']) ? $payload['keterangan'] : 'IZIN');
  $alasan  = isset($payload['alasan']) ? trim((string)$payload['alasan']) : '';

  // ambil tanggal
  $mulai = '';
  if (isset($payload['mulai'])) {
    $mulai = trim((string)$payload['mulai']);
  } elseif (isset($payload['tanggal_mulai'])) {
    $mulai = trim((string)$payload['tanggal_mulai']);
  }

  $selesai = '';
  if (isset($payload['selesai'])) {
    $selesai = trim((string)$payload['selesai']);
  } elseif (isset($payload['tanggal_selesai'])) {
    $selesai = trim((string)$payload['tanggal_selesai']);
  }

  $status = isset($payload['status']) ? trim((string)$payload['status']) : 'pending';
  if ($status === '') $status = 'pending';

  // Validasi
  if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'user_id wajib.']);
    exit;
  }
  if (!is_ymd($mulai) || !is_ymd($selesai)) {
    echo json_encode(['success' => false, 'message' => 'Tanggal harus format YYYY-MM-DD.']);
    exit;
  }
  if ($selesai < $mulai) {
    echo json_encode(['success' => false, 'message' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
    exit;
  }
  if ($ket === 'IZIN' && $colAlasan !== null && $alasan === '') {
    echo json_encode(['success' => false, 'message' => 'Alasan wajib diisi untuk keterangan IZIN.']);
    exit;
  }

  // susun kolom & values (pakai real_escape_string, tanpa prepared)
  $cols   = array();
  $values = array();

  $cols[]   = 'user_id';
  $values[] = "'" . $conn->real_escape_string((string)$user_id) . "'";

  $cols[]   = $colMulai;
  $values[] = "'" . $conn->real_escape_string($mulai) . "'";

  $cols[]   = $colSelesai;
  $values[] = "'" . $conn->real_escape_string($selesai) . "'";

  $cols[]   = $colStatus;
  $values[] = "'" . $conn->real_escape_string($status) . "'";

  if ($colKeterangan !== null) {
    $cols[]   = $colKeterangan;
    $values[] = "'" . $conn->real_escape_string($ket) . "'";
  }

  if ($colAlasan !== null) {
    $cols[]   = $colAlasan;
    $values[] = "'" . $conn->real_escape_string($alasan) . "'";
  }

  $colsSql = array();
  foreach ($cols as $c) {
    $colsSql[] = '`' . $c . '`';
  }

  $sqlIns = "INSERT INTO izin (" . implode(',', $colsSql) . ") VALUES (" . implode(',', $values) . ")";

  if (!$conn->query($sqlIns)) {
    echo json_encode([
      'success' => false,
      'message' => 'SQL INSERT gagal: ' . $conn->error,
      'sql'     => $sqlIns,
    ]);
    exit;
  }

  $newId = (int)$conn->insert_id;

  echo json_encode(['success' => true, 'id' => $newId]);
  exit;
}

/* ============================================================
   GET — LIST
============================================================ */

$q        = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$status   = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$dari     = isset($_GET['dari']) ? trim((string)$_GET['dari']) : '';
$sampai   = isset($_GET['sampai']) ? trim((string)$_GET['sampai']) : '';
$userFilt = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$limit    = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
$offset   = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

$whereParts = array();

/* filter user */
if ($userFilt > 0) {
  $whereParts[] = "i.user_id = " . $userFilt;
}

/* filter q (username / nama / alasan) */
if ($q !== '') {
  $escLike = $conn->real_escape_string('%' . $q . '%');
  $sub = array();
  $sub[] = "u.username LIKE '" . $escLike . "'";
  if ($userNameCol !== null) {
    $sub[] = "u." . $userNameCol . " LIKE '" . $escLike . "'";
  }
  if ($colAlasan !== null) {
    $sub[] = "i." . $colAlasan . " LIKE '" . $escLike . "'";
  }
  $whereParts[] = '(' . implode(' OR ', $sub) . ')';
}

/* filter status */
if ($status !== '') {
  $escStatus = $conn->real_escape_string($status);
  $whereParts[] = "i.$colStatus = '" . $escStatus . "'";
}

/* filter tanggal overlap */
if ($dari !== '' && $sampai !== '') {
  $escDari   = $conn->real_escape_string($dari);
  $escSampai = $conn->real_escape_string($sampai);
  $whereParts[] = "(i.$colMulai <= '" . $escSampai . "' AND i.$colSelesai >= '" . $escDari . "')";
} elseif ($dari !== '') {
  $escDari = $conn->real_escape_string($dari);
  $whereParts[] = "i.$colSelesai >= '" . $escDari . "'";
} elseif ($sampai !== '') {
  $escSampai = $conn->real_escape_string($sampai);
  $whereParts[] = "i.$colMulai <= '" . $escSampai . "'";
}

$whereSql = '';
if (!empty($whereParts)) {
  $whereSql = 'WHERE ' . implode(' AND ', $whereParts);
}

/* ==== TOTAL ==== */
$sqlCount = "
  SELECT COUNT(*) AS total
  FROM izin i
  JOIN users u ON u.id = i.user_id
  $whereSql
";

$resC = $conn->query($sqlCount);
if (!$resC) {
  echo json_encode([
    'success' => false,
    'message' => 'SQL COUNT gagal: ' . $conn->error,
    'sql'     => $sqlCount,
  ]);
  exit;
}
$rowC  = $resC->fetch_assoc();
$total = $rowC ? (int)$rowC['total'] : 0;
$resC->close();

/* ==== DATA ==== */

// select nama
$selectNama = ", '' AS nama";
if ($userNameCol !== null) {
  $selectNama = ", u." . $userNameCol . " AS nama";
}

// select alasan
$selectAlasan = ", '' AS alasan";
if ($colAlasan !== null) {
  $selectAlasan = ", i." . $colAlasan . " AS alasan";
}

// select keterangan
$selectKet = ", '' AS keterangan";
if ($colKeterangan !== null) {
  $selectKet = ", i." . $colKeterangan . " AS keterangan";
}

// field tanggal dikembalikan dengan 2 nama (mulai/selesai & tanggal_mulai/tanggal_selesai)
$sqlData = "
  SELECT
    i.id,
    i.user_id,
    i.$colStatus AS status,
    DATE_FORMAT(i.$colMulai,   '%Y-%m-%d') AS mulai,
    DATE_FORMAT(i.$colMulai,   '%Y-%m-%d') AS tanggal_mulai,
    DATE_FORMAT(i.$colSelesai, '%Y-%m-%d') AS selesai,
    DATE_FORMAT(i.$colSelesai, '%Y-%m-%d') AS tanggal_selesai,
    i.created_at,
    u.username
    $selectNama
    $selectAlasan
    $selectKet
  FROM izin i
  JOIN users u ON u.id = i.user_id
  $whereSql
  ORDER BY i.created_at DESC, i.id DESC
  LIMIT $limit OFFSET $offset
";

$res = $conn->query($sqlData);
if (!$res) {
  echo json_encode([
    'success' => false,
    'message' => 'SQL DATA gagal: ' . $conn->error,
    'sql'     => $sqlData,
  ]);
  exit;
}

$rows = array();
while ($r = $res->fetch_assoc()) {
  $mulaiDt   = new DateTime($r['mulai']);
  $selesaiDt = new DateTime($r['selesai']);
  $dur       = $mulaiDt->diff($selesaiDt)->days + 1;
  if ($dur < 1) $dur = 1;
  $r['durasi_hari'] = (int)$dur;
  $rows[] = $r;
}
$res->close();

echo json_encode(array(
  'success' => true,
  'total'   => $total,
  'data'    => $rows,
));
