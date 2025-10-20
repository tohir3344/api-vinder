<?php
declare(strict_types=1);
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");

require_once __DIR__ . '/../Koneksi.php';

const LEMBUR_START_CUTOFF = '10:00:00';
const LEMBUR_END_CUTOFF   = '17:20:00'; // ganti sesuai kebijakan (sebelumnya kamu test '09:40:00')
const DEFAULT_RATE        = 10000;      // upah per jam (flat), karena kolom upah_per_jam DIHAPUS

/** Normalisasi ke HH:MM:SS; terima '16.10' -> '16:10:00' */
function toTimeOrNull($v) {
  if (!isset($v)) return null;
  $t = trim((string)$v);
  if ($t === '') return null;
  $t = str_replace('.', ':', $t); // dukung '16.10'
  if (!preg_match('/^\d{1,2}:\d{1,2}(:\d{1,2})?$/', $t)) return null;
  $parts = explode(':', $t);
  $H = str_pad((string)($parts[0] ?? '0'), 2, '0', STR_PAD_LEFT);
  $M = str_pad((string)($parts[1] ?? '0'), 2, '0', STR_PAD_LEFT);
  $S = str_pad((string)($parts[2] ?? '0'), 2, '0', STR_PAD_LEFT);
  return "$H:$M:$S";
}

/** Konversi HH:MM:SS -> total menit (abaikan detik) */
function toMinutes(string $hhmmss): int {
  [$H,$M,$S] = array_map('intval', explode(':', $hhmmss));
  return $H * 60 + $M;
}

/** Selisih menit pada tanggal tertentu (robust TZ) */
function minutesDiff(string $date, string $t1, string $t2): int {
  return (int) round( (strtotime("$date $t2") - strtotime("$date $t1")) / 60 );
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
  }

  $raw = file_get_contents('php://input');
  $json = json_decode($raw, true);
  if (!is_array($json)) throw new Exception('Invalid JSON');

  $user_id    = (int)($json['user_id'] ?? 0);
  $tanggal    = isset($json['tanggal']) ? trim((string)$json['tanggal']) : '';
  $alasan     = array_key_exists('alasan', $json) ? ( ($json['alasan'] ?? '') !== '' ? trim((string)$json['alasan']) : null ) : null;

  // kamu sudah hapus kolom upah_per_jam -> pakai DEFAULT_RATE
  $rate       = DEFAULT_RATE;

  $jam_masuk  = toTimeOrNull($json['jam_masuk']  ?? null);
  $jam_keluar = toTimeOrNull($json['jam_keluar'] ?? null);

  if ($user_id <= 0 || $tanggal === '') throw new Exception('user_id dan tanggal wajib diisi');

  // Ambil jam dari tabel absen bila tidak dikirim / invalid
  if ($jam_masuk === null || $jam_keluar === null) {
    $stmt = $conn->prepare("
      SELECT jam_masuk, jam_keluar
      FROM absen
      WHERE user_id=? AND tanggal=?
      ORDER BY id DESC
      LIMIT 1
    ");
    $stmt->bind_param("is", $user_id, $tanggal);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      if ($jam_masuk  === null) $jam_masuk  = toTimeOrNull($row['jam_masuk'] ?? null);
      if ($jam_keluar === null) $jam_keluar = toTimeOrNull($row['jam_keluar'] ?? null);
    }
  }

  if ($jam_masuk === null && $jam_keluar === null) {
    throw new Exception('Tidak ditemukan jam_masuk/jam_keluar untuk tanggal tsb');
  }

  // ---- Hitung total menit lembur ----
  $total_menit = 0;

  if ($jam_masuk !== null) {
    $inMin    = toMinutes($jam_masuk);
    $startMin = toMinutes(LEMBUR_START_CUTOFF);
    if ($inMin < $startMin) {
      $total_menit += max(0, minutesDiff($tanggal, $jam_masuk, LEMBUR_START_CUTOFF));
    }
  }

  if ($jam_keluar !== null) {
    $outMin = toMinutes($jam_keluar);
    $endMin = toMinutes(LEMBUR_END_CUTOFF);
    if ($outMin >= $endMin) { // include tepat di cutoff
      $total_menit += max(0, minutesDiff($tanggal, LEMBUR_END_CUTOFF, $jam_keluar));
    }
  }

  // Jika tidak ada menit lembur -> hapus entri lembur agar bersih
  if ($total_menit <= 0) {
    $del = $conn->prepare("DELETE FROM lembur WHERE user_id=? AND tanggal=?");
    $del->bind_param("is", $user_id, $tanggal);
    $del->execute();
    echo json_encode([
      "success"     => true,
      "deleted"     => ($del->affected_rows > 0),
      "total_menit" => 0,
      "total_jam"   => 0,
      "total_upah"  => 0,
    ]);
    exit;
  }

  // Pembulatan jam: step 1 jam (tanpa desimal, sesuai instruksi kamu)
  $total_jam  = intdiv($total_menit, 60);    // 0,1,2,…
  $total_upah = (int)($total_jam * $rate);   // rate flat per jam

  // === UPSERT lembur TANPA kolom upah_per_jam ===
  $sql = "INSERT INTO lembur (
            user_id, tanggal, jam_masuk, jam_keluar, alasan,
            total_menit, total_jam, total_upah
          ) VALUES (?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
            jam_masuk   = VALUES(jam_masuk),
            jam_keluar  = VALUES(jam_keluar),
            alasan      = VALUES(alasan),
            total_menit = VALUES(total_menit),
            total_jam   = VALUES(total_jam),
            total_upah  = VALUES(total_upah)";
  $st = $conn->prepare($sql);

  // NOTE: NULL aman untuk kolom jam_masuk/jam_keluar/alasan bila tipe kolomnya NULLable
  $st->bind_param(
    "issssiii",
    $user_id,
    $tanggal,
    $jam_masuk,   // bisa null
    $jam_keluar,  // bisa null
    $alasan,      // bisa null
    $total_menit,
    $total_jam,
    $total_upah
  );

  if (!$st->execute()) {
    throw new Exception("DB error: " . $st->error);
  }

  echo json_encode([
    "success"       => true,
    "user_id"       => $user_id,
    "tanggal"       => $tanggal,
    "jam_masuk"     => $jam_masuk,
    "jam_keluar"    => $jam_keluar,
    "alasan"        => $alasan,
    "total_menit"   => $total_menit,
    "total_jam"     => $total_jam,
    "total_upah"    => $total_upah,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode([
    "success" => false,
    "message" => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
