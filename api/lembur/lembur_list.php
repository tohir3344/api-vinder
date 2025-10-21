<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** ===== Aturan (sesuai permintaan) =====
 * Lembur dihitung:
 * - SEBELUM 08:00  => total_menit_masuk
 * - SETELAH 17:00  => total_menit_keluar
 * total_menit = masuk + keluar
 * Tidak ada upah.
 */
const DB_NAME      = 'penggajian_db';
const START_CUTOFF = '08:00';
const END_CUTOFF   = '17:00';

/** ===== Utils waktu ===== */
function parseHm(string $hhmm): ?array {
  // Support "HH:mm" atau "HH:mm:ss" → ambil bagian menitnya
  if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hhmm)) return null;
  [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5))); // ambil "HH:mm"
  if ($h < 0 || $h > 23 || $m < 0 || $m > 59) return null;
  return [$h, $m];
}
function toMinutes(string $hhmm): ?int {
  $p = parseHm($hhmm); if (!$p) return null;
  return $p[0] * 60 + $p[1];
}
function minutesToHMM(int $minutes): string {
  $h = intdiv($minutes, 60);
  $m = $minutes % 60;
  return sprintf('%d:%02d', $h, $m);
}

/** Hitung split lembur: [menitMasuk, menitKeluar]
 * - menitMasuk  = waktu sebelum 08:00
 * - menitKeluar = waktu setelah 17:00
 * Support cross-midnight (keluar < masuk → +24h).
 */
function computeOvertimeSplit(string $jamMasuk, string $jamKeluar): array {
  $in  = toMinutes($jamMasuk);
  $out = toMinutes($jamKeluar);
  if ($in === null || $out === null) return [0, 0];
  if ($out < $in) $out += 24 * 60; // shift lewat tengah malam

  $startCut = toMinutes(START_CUTOFF); // 480
  $endCut   = toMinutes(END_CUTOFF);   // 1020

  // Lembur sebelum 08:00: dari jamMasuk sampai min(08:00, jamKeluar)
  $masuk = 0;
  if ($in < $startCut) {
    $masuk = max(0, min($startCut, $out) - $in);
  }

  // Lembur setelah 17:00: dari max(17:00, jamMasuk) sampai jamKeluar
  $keluar = 0;
  if ($out > $endCut) {
    $keluar = max(0, $out - max($endCut, $in));
  }

  return [$masuk, $keluar];
}

/** ===== Helper: cek kolom exist (sekali per request) ===== */
function column_exists(mysqli $c, string $db, string $t, string $col): bool {
  $sql = "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $stmt = $c->prepare($sql);
  $stmt->bind_param('sss', $db, $t, $col);
  $stmt->execute();
  $res = $stmt->get_result();
  return (bool)$res->fetch_row();
}

try {
  $db = new mysqli("localhost", "root", "", DB_NAME);
  $db->set_charset("utf8mb4");

  // Cache ketersediaan kolom (opsional)
  $HAS_JAM              = column_exists($db, DB_NAME, 'lembur', 'total_jam');
  $HAS_MENIT_MASUK      = column_exists($db, DB_NAME, 'lembur', 'total_menit_masuk');
  $HAS_MENIT_KELUAR     = column_exists($db, DB_NAME, 'lembur', 'total_menit_keluar');

  $method = $_SERVER['REQUEST_METHOD'];
  $action = $_GET['action'] ?? ($_POST['action'] ?? '');

  /* ===================== GET: list ===================== *
   * Query opsional: start, end, user_id, q (nama)
   * Return: total_menit_masuk, total_menit_keluar, total_menit, total_jam
   */
  if ($method === 'GET' && $action === 'list') {
    $where=[]; $params=[]; $types='';

    if (!empty($_GET['start']))   { $where[]='l.tanggal >= ?'; $params[]=$_GET['start']; $types.='s'; }
    if (!empty($_GET['end']))     { $where[]='l.tanggal <= ?'; $params[]=$_GET['end'];   $types.='s'; }
    if (!empty($_GET['user_id'])) { $where[]='l.user_id = ?';  $params[]=(int)$_GET['user_id']; $types.='i'; }
    if (!empty($_GET['q']))       { $where[]='COALESCE(u.nama_lengkap, u.username) LIKE ?'; $params[]='%'.$_GET['q'].'%'; $types.='s'; }

    $selectExtra = [];
    if ($HAS_JAM)          $selectExtra[] = 'l.total_jam';
    if ($HAS_MENIT_MASUK)  $selectExtra[] = 'l.total_menit_masuk';
    if ($HAS_MENIT_KELUAR) $selectExtra[] = 'l.total_menit_keluar';
    $selectExtraSql = $selectExtra ? ', '.implode(', ', $selectExtra) : '';

    $sql = "
      SELECT l.id, l.user_id, l.tanggal, l.jam_masuk, l.jam_keluar, l.alasan, l.total_menit,
             COALESCE(u.nama_lengkap, u.username) AS nama
             $selectExtraSql
      FROM lembur l
      JOIN users u ON u.id = l.user_id
    ";
    if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
    $sql .= ' ORDER BY l.tanggal DESC, l.jam_masuk ASC';

    $stmt = $db->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
      $r['id']          = (int)$r['id'];
      $r['user_id']     = (int)$r['user_id'];
      $r['total_menit'] = isset($r['total_menit']) ? (int)$r['total_menit'] : 0;

      // derive menit_masuk / menit_keluar kalau kolom belum ada
      $menit_masuk  = $HAS_MENIT_MASUK  && isset($r['total_menit_masuk'])  ? (int)$r['total_menit_masuk']  : null;
      $menit_keluar = $HAS_MENIT_KELUAR && isset($r['total_menit_keluar']) ? (int)$r['total_menit_keluar'] : null;

      if ($menit_masuk === null || $menit_keluar === null) {
        // Hitung dari jam untuk memastikan respons lengkap
        [$mMasuk, $mKeluar] = computeOvertimeSplit((string)$r['jam_masuk'], (string)$r['jam_keluar']);
        if ($menit_masuk === null)  $menit_masuk = $mMasuk;
        if ($menit_keluar === null) $menit_keluar = $mKeluar;
      }

      $r['total_menit_masuk']  = $menit_masuk;
      $r['total_menit_keluar'] = $menit_keluar;

      // total_menit konsisten = masuk + keluar
      $total_calc = $menit_masuk + $menit_keluar;
      $r['total_menit'] = $total_calc;

      // total_jam (kolom ada → pakai; kalau tidak → hitung dari total_calc)
      $r['total_jam'] = ($HAS_JAM && isset($r['total_jam']) && $r['total_jam'] !== null)
                        ? (string)$r['total_jam'] : minutesToHMM($total_calc);

      $rows[] = $r;
    }
    echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* ===================== GET: rekap ===================== *
   * Rekap per user: sum menit_masuk, sum menit_keluar, total
   */
  if ($method === 'GET' && $action === 'rekap') {
    $where=[]; $params=[]; $types='';
    if (!empty($_GET['start']))   { $where[]='l.tanggal >= ?'; $params[]=$_GET['start']; $types.='s'; }
    if (!empty($_GET['end']))     { $where[]='l.tanggal <= ?'; $params[]=$_GET['end'];   $types.='s'; }
    if (!empty($_GET['user_id'])) { $where[]='l.user_id = ?';  $params[]=(int)$_GET['user_id']; $types.='i'; }

    $canSumInSql  = $HAS_MENIT_MASUK;
    $canSumOutSql = $HAS_MENIT_KELUAR;

    if ($canSumInSql && $canSumOutSql) {
      $sql = "
        SELECT l.user_id, COALESCE(u.nama_lengkap, u.username) AS nama,
               COALESCE(SUM(l.total_menit_masuk),0)  AS sum_masuk,
               COALESCE(SUM(l.total_menit_keluar),0) AS sum_keluar
        FROM lembur l
        JOIN users u ON u.id = l.user_id
      ";
      if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
      $sql .= ' GROUP BY l.user_id, nama ORDER BY nama ASC';

      $stmt = $db->prepare($sql);
      if ($params) $stmt->bind_param($types, ...$params);
      $stmt->execute();
      $res = $stmt->get_result();

      $rows = [];
      while ($r = $res->fetch_assoc()) {
        $masuk  = (int)$r['sum_masuk'];
        $keluar = (int)$r['sum_keluar'];
        $total  = $masuk + $keluar;
        $rows[] = [
          'user_id'             => (int)$r['user_id'],
          'nama'                => $r['nama'],
          'total_menit_masuk'   => $masuk,
          'total_menit_keluar'  => $keluar,
          'total_menit'         => $total,
          'total_jam'           => minutesToHMM($total),
        ];
      }
      echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
      exit;
    } else {
      // Fallback: tarik baris2 lalu agregasi di PHP
      $sql = "
        SELECT l.user_id, COALESCE(u.nama_lengkap, u.username) AS nama,
               l.jam_masuk, l.jam_keluar
        FROM lembur l
        JOIN users u ON u.id = l.user_id
      ";
      if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
      $sql .= ' ORDER BY l.user_id ASC, l.tanggal ASC';

      $stmt = $db->prepare($sql);
      if ($params) $stmt->bind_param($types, ...$params);
      $stmt->execute();
      $res = $stmt->get_result();

      $agg = []; // user_id => [nama, masuk, keluar]
      while ($row = $res->fetch_assoc()) {
        $uid = (int)$row['user_id'];
        if (!isset($agg[$uid])) $agg[$uid] = ['nama' => $row['nama'], 'masuk' => 0, 'keluar' => 0];
        [$mIn, $mOut] = computeOvertimeSplit((string)$row['jam_masuk'], (string)$row['jam_keluar']);
        $agg[$uid]['masuk']  += $mIn;
        $agg[$uid]['keluar'] += $mOut;
      }
      $rows=[];
      foreach ($agg as $uid => $v) {
        $total = $v['masuk'] + $v['keluar'];
        $rows[] = [
          'user_id'            => $uid,
          'nama'               => $v['nama'],
          'total_menit_masuk'  => $v['masuk'],
          'total_menit_keluar' => $v['keluar'],
          'total_menit'        => $total,
          'total_jam'          => minutesToHMM($total),
        ];
      }
      echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  /* ===================== POST: create / edit ===================== *
   * Body JSON:
   * {
   *   "action": "create" | "edit",
   *   "data": {
   *     "id"?        : number (wajib untuk edit)
   *     "user_id"?   : number  | atau "nama": string
   *     "tanggal"    : "YYYY-MM-DD",
   *     "jam_masuk"  : "HH:MM"|"HH:MM:SS",
   *     "jam_keluar" : "HH:MM"|"HH:MM:SS",
   *     "alasan"     : string
   *   }
   * }
   * Catatan: server SELALU hitung ulang split (masuk/keluar) kalau jam valid.
   */
  if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) { http_response_code(400); echo json_encode(['error'=>'Data tidak valid']); exit; }

    $data = $input['data'] ?? null;
    if (!$data) { http_response_code(400); echo json_encode(['error'=>'Data lembur kosong']); exit; }

    // user_id atau nama
    $user_id = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    if ($user_id <= 0) {
      $nama = trim((string)($data['nama'] ?? ''));
      if ($nama === '') { http_response_code(400); echo json_encode(['error'=>'Nama atau user_id wajib diisi']); exit; }
      $stmtU = $db->prepare("SELECT id FROM users WHERE COALESCE(nama_lengkap, username) = ? LIMIT 1");
      $stmtU->bind_param('s', $nama);
      $stmtU->execute();
      $resU = $stmtU->get_result();
      if ($resU->num_rows === 0) { http_response_code(400); echo json_encode(['error'=>"User '$nama' tidak ditemukan"]); exit; }
      $user_id = (int)$resU->fetch_assoc()['id'];
    }

    $tanggal    = (string)($data['tanggal'] ?? '');
    $jam_masuk  = (string)($data['jam_masuk'] ?? '');
    $jam_keluar = (string)($data['jam_keluar'] ?? '');
    $alasan     = (string)($data['alasan'] ?? '');

    $hasInOut = (parseHm($jam_masuk) !== null && parseHm($jam_keluar) !== null);
    [$mMasuk, $mKeluar] = $hasInOut ? computeOvertimeSplit($jam_masuk, $jam_keluar) : [0, 0];
    $total_menit = $mMasuk + $mKeluar;
    $total_jam   = minutesToHMM($total_menit);

    if (($input['action'] ?? '') === 'create') {
      if ($HAS_JAM && $HAS_MENIT_MASUK && $HAS_MENIT_KELUAR) {
        $stmt = $db->prepare("
          INSERT INTO lembur (user_id, tanggal, jam_masuk, jam_keluar, alasan,
                              total_menit, total_jam, total_menit_masuk, total_menit_keluar)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
          'issssisii', // i s s s s i s i i
          $user_id, $tanggal, $jam_masuk, $jam_keluar, $alasan,
          $total_menit, $total_jam, $mMasuk, $mKeluar
        );
      } else {
        $stmt = $db->prepare("
          INSERT INTO lembur (user_id, tanggal, jam_masuk, jam_keluar, alasan, total_menit)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('issssi',
          $user_id, $tanggal, $jam_masuk, $jam_keluar, $alasan, $total_menit
        );
      }
      $stmt->execute();
      echo json_encode([
        'success'             => true,
        'id'                  => $stmt->insert_id,
        'total_menit_masuk'   => $mMasuk,
        'total_menit_keluar'  => $mKeluar,
        'total_menit'         => $total_menit,
        'total_jam'           => $total_jam
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (($input['action'] ?? '') === 'edit') {
      $id = (int)($data['id'] ?? 0);
      if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'ID lembur tidak valid']); exit; }

      if ($HAS_JAM && $HAS_MENIT_MASUK && $HAS_MENIT_KELUAR) {
        $stmt = $db->prepare("
          UPDATE lembur SET user_id=?, tanggal=?, jam_masuk=?, jam_keluar=?, alasan=?,
                            total_menit=?, total_jam=?, total_menit_masuk=?, total_menit_keluar=?
          WHERE id=?
        ");
        $stmt->bind_param(
          'issssisiii', // i s s s s i s i i i
          $user_id, $tanggal, $jam_masuk, $jam_keluar, $alasan,
          $total_menit, $total_jam, $mMasuk, $mKeluar, $id
        );
      } else {
        $stmt = $db->prepare("
          UPDATE lembur SET user_id=?, tanggal=?, jam_masuk=?, jam_keluar=?, alasan=?, total_menit=?
          WHERE id=?
        ");
        $stmt->bind_param('issssii',
          $user_id, $tanggal, $jam_masuk, $jam_keluar, $alasan, $total_menit, $id
        );
      }
      $stmt->execute();

      echo json_encode([
        'success'             => true,
        'total_menit_masuk'   => $mMasuk,
        'total_menit_keluar'  => $mKeluar,
        'total_menit'         => $total_menit,
        'total_jam'           => $total_jam
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    http_response_code(400);
    echo json_encode(['error' => "Action '".($input['action'] ?? '')."' tidak dikenali"], JSON_UNESCAPED_UNICODE);
    exit;
  }

  http_response_code(400);
  echo json_encode(['error' => "Action '$action' tidak dikenali"], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Server error: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
