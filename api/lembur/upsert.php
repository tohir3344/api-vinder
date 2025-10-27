<?php
declare(strict_types=1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';
require_once __DIR__ . '/config_lembur.php'; // <- pakai pusat jam kamu

/* ===== Config ===== */
if (!defined('LE_START_CUTOFF'))   define('LE_START_CUTOFF',   '08:00');
if (!defined('LE_END_CUTOFF'))     define('LE_END_CUTOFF',     '17:00');
if (!defined('LE_RATE_PER_JAM'))   define('LE_RATE_PER_JAM',   10000);
if (!defined('LE_RATE_PER_MENIT')) define('LE_RATE_PER_MENIT', (float)LE_RATE_PER_JAM / 60.0);

/* ===== Helpers ===== */
function normHHMMSS($v){
  if($v===null) return null;
  $t=trim((string)$v); if($t==='') return null;
  $t=str_replace('.',':',$t);
  if(!preg_match('/^\d{1,2}:\d{1,2}(:\d{1,2})?$/',$t)) return null;
  $p=explode(':',$t);
  return sprintf('%02d:%02d:%02d',(int)($p[0]??0),(int)($p[1]??0),(int)($p[2]??0));
}
function dt($date,$hhmmss,$addDays=0){
  $tz=new DateTimeZone('Asia/Jakarta');
  $base=new DateTimeImmutable("$date $hhmmss",$tz);
  return $addDays? $base->modify("+$addDays day") : $base;
}
function minutesBetween(DateTimeImmutable $a, DateTimeImmutable $b){
  $d=$b->getTimestamp()-$a->getTimestamp();
  return $d>0? intdiv($d,60):0;
}
function isValidDateYmd($d){
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) return false;
  [$y,$m,$da]=array_map('intval',explode('-',$d));
  return checkdate($m,$da,$y);
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
  }

  $j = json_decode(file_get_contents('php://input'), true);
  if (!is_array($j)) throw new Exception('Invalid JSON');

  $user_id = (int)($j['user_id'] ?? 0);
  $tanggal = trim((string)($j['tanggal'] ?? ''));
  if ($user_id <= 0 || $tanggal === '') throw new Exception('user_id dan tanggal wajib');
  if (!isValidDateYmd($tanggal)) throw new Exception('Format tanggal harus YYYY-MM-DD');

  $alasan        = array_key_exists('alasan', $j) ? (trim((string)$j['alasan']) ?: null) : null;
  $alasan_keluar = array_key_exists('alasan_keluar', $j) ? (trim((string)$j['alasan_keluar']) ?: null) : null;

  // terima jam dari caller
  $jam_masuk  = normHHMMSS($j['jam_masuk']  ?? null);
  $jam_keluar = normHHMMSS($j['jam_keluar'] ?? null);

  // fallback ke tabel absen kalau perlu
  if ($jam_masuk === null || $jam_keluar === null) {
    $q = $conn->prepare("SELECT jam_masuk, jam_keluar FROM absen WHERE user_id=? AND tanggal=? ORDER BY id DESC LIMIT 1");
    $q->bind_param('is', $user_id, $tanggal);
    $q->execute();
    $abs = $q->get_result()->fetch_assoc();
    $q->close();
    if ($jam_masuk  === null) $jam_masuk  = normHHMMSS($abs['jam_masuk']  ?? null);
    if ($jam_keluar === null) $jam_keluar = normHHMMSS($abs['jam_keluar'] ?? null);
  }
  if ($jam_masuk === null && $jam_keluar === null) throw new Exception('Tidak ditemukan jam_masuk/jam_keluar pada tanggal tsb');

  $cutIn  = normHHMMSS(LE_START_CUTOFF);
  $cutOut = normHHMMSS(LE_END_CUTOFF);
  if ($cutIn === null || $cutOut === null) throw new Exception('Config cutoff tidak valid');

  // hitung menit lembur
  $menitMasuk = 0;
  if ($jam_masuk) {
    $dtMasuk = dt($tanggal, $jam_masuk);
    $dtCutIn = dt($tanggal, $cutIn);
    if ($dtMasuk < $dtCutIn) $menitMasuk = minutesBetween($dtMasuk, $dtCutIn);
  }
  $menitKeluar = 0;
  if ($jam_keluar) {
    $cross = strcmp($cutOut, $cutIn) <= 0; // end<=start → cutOut H+1
    if (isset($dtMasuk)) {
      $cand = dt($tanggal, $jam_keluar);
      $dtKeluar = ($cand < $dtMasuk) ? dt($tanggal, $jam_keluar, 1) : $cand;
    } else {
      $dtKeluar = ($cross && strcmp($jam_keluar, $cutOut) < 0) ? dt($tanggal, $jam_keluar, 1) : dt($tanggal, $jam_keluar);
    }
    $dtCutOut = $cross ? dt($tanggal, $cutOut, 1) : dt($tanggal, $cutOut);
    if ($dtKeluar > $dtCutOut) $menitKeluar = minutesBetween($dtCutOut, $dtKeluar);
  }

  $total_menit      = max(0, $menitMasuk + $menitKeluar);
  $total_upah_float = $total_menit * (float)LE_RATE_PER_MENIT;
  $total_upah_int   = (int)round($total_upah_float); // kolom INT
  $total_jam_dec    = round($total_menit / 60.0, 2); // kolom DECIMAL(6,2)

  // selalu gunakan kolom alasan_keluar (kolomnya memang ada)
  $sql = "
    INSERT INTO `lembur`
      (user_id, tanggal, jam_masuk, jam_keluar, alasan, alasan_keluar,
       total_menit_masuk, total_menit_keluar, total_menit, total_upah, total_jam)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      jam_masuk          = COALESCE(VALUES(jam_masuk),  jam_masuk),
      jam_keluar         = COALESCE(VALUES(jam_keluar), jam_keluar),
      alasan             = COALESCE(VALUES(alasan),     alasan),
      alasan_keluar      = COALESCE(VALUES(alasan_keluar), alasan_keluar),
      total_menit_masuk  = VALUES(total_menit_masuk),
      total_menit_keluar = VALUES(total_menit_keluar),
      total_menit        = VALUES(total_menit),
      total_upah         = VALUES(total_upah),
      total_jam          = VALUES(total_jam)
  ";
  $st = $conn->prepare($sql);
  $st->bind_param(
    'isssssiiiid',
    $user_id, $tanggal, $jam_masuk, $jam_keluar, $alasan, $alasan_keluar,
    $menitMasuk, $menitKeluar, $total_menit, $total_upah_int, $total_jam_dec
  );
  $st->execute();
  $st->close();

  echo json_encode([
    'success'=>true,
    'payload'=>[
      'user_id'=>$user_id,'tanggal'=>$tanggal,
      'jam_masuk'=>$jam_masuk,'jam_keluar'=>$jam_keluar,
      'alasan'=>$alasan,'alasan_keluar'=>$alasan_keluar,
    ],
    'hasil'=>[
      'total_menit_masuk'=>$menitMasuk,'total_menit_keluar'=>$menitKeluar,
      'total_menit'=>$total_menit,'total_jam'=>$total_jam_dec,'total_upah'=>$total_upah_int,
    ],
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
