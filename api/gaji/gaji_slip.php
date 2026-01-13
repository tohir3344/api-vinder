<?php
// api/gaji/gaji_slip.php
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
  echo json_encode(["success"=>false,"message"=>"Koneksi DB tidak ditemukan"]);
  exit;
}
$mysqli->set_charset('utf8mb4');

function qs(?string $k, ?string $def=null){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $def; }
function parse_others_json($jsonString) {
    if (!$jsonString) return [];
    $decoded = json_decode((string)$jsonString, true);
    if (!is_array($decoded)) return [];
    $clean = [];
    foreach ($decoded as $it) {
        if (!is_array($it)) continue;
        $label  = isset($it['label']) ? trim((string)$it['label']) : '';
        $amount = isset($it['amount']) ? (int)$it['amount'] : 0;
        if ($label === '' && $amount <= 0) continue;
        $clean[] = ['label' => $label ?: 'Lainnya', 'amount' => $amount];
    }
    return $clean;
}

try {
  $user_id = (int)(qs('user_id') ?? 0);
  $start   = qs('start'); 
  $end     = qs('end');   
  
  if ($user_id <= 0 || !$start || !$end) {
    http_response_code(400);
    echo json_encode(["success"=>false,"message"=>"Parameter user_id, start, end wajib diisi"]);
    exit;
  }

  // 1. AMBIL HISTORY GAJI (Tetap sama)
  $sql = "SELECT * FROM gaji_run 
          WHERE user_id = ? 
          AND periode_start >= ? 
          AND periode_start <= ?
          ORDER BY periode_start ASC";

  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param("iss", $user_id, $start, $end);
  $stmt->execute();
  $result = $stmt->get_result();

  $slips = [];
  while ($row = $result->fetch_assoc()) {
      $slips[] = $row;
  }

  if (empty($slips)) {
      echo json_encode(["success"=>true, "data"=>[]]); 
      exit;
  }

  // 2. SETUP NILAI DEFAULT (Penting biar gak NULL)
  $accumulated = [
      'id' => $slips[0]['id'], 
      'user_id' => $user_id,
      'nama' => '', 
      'periode_start' => $slips[0]['periode_start'], 
      'periode_end' => $slips[count($slips)-1]['periode_end'], 
      'status_bayar' => 'paid', 
      
      'hadir_minggu' => 0, 'lembur_menit' => 0, 'lembur_rp' => 0,
      'gaji_pokok_rp' => 0, 'angsuran_rp' => 0,
      'thr_rp' => 0, 'bonus_akhir_tahun_rp' => 0, 'others_total_rp' => 0,
      'total_gaji_rp' => 0,
      
      'rate_per_jam' => 10000,      
      'gaji_pokok_harian' => 0, // <-- HARUSNYA INI KEISI 
      
      'others_json' => [] 
  ];

  $allOthersList = [];

  foreach ($slips as $row) {
      $accumulated['hadir_minggu']   += (int)($row['hadir_minggu'] ?? 0);
      $accumulated['lembur_menit']   += (int)($row['lembur_menit'] ?? 0);
      $accumulated['lembur_rp']      += (int)($row['lembur_rp'] ?? 0);
      $accumulated['gaji_pokok_rp']  += (int)($row['gaji_pokok_rp'] ?? 0); // Ini Total
      $accumulated['angsuran_rp']    += (int)($row['angsuran_rp'] ?? 0);
      
      $thr = (int)($row['thr_rp'] ?? $row['kerajinan_rp'] ?? 0);
      $accumulated['thr_rp'] += $thr;

      $bonus = (int)($row['bonus_akhir_tahun_rp'] ?? $row['kebersihan_rp'] ?? 0);
      $accumulated['bonus_akhir_tahun_rp'] += $bonus;

      $othersTotal = (int)($row['others_total_rp'] ?? $row['ibadah_rp'] ?? 0);
      $accumulated['others_total_rp'] += $othersTotal;

      $accumulated['total_gaji_rp']  += (int)($row['total_gaji_rp'] ?? 0);

      if (($row['status_bayar'] ?? '') !== 'paid') {
          $accumulated['status_bayar'] = 'unpaid';
      }

      $items = parse_others_json($row['others_json'] ?? null);
      foreach ($items as $item) {
          $allOthersList[] = $item;
      }
  }

  $mergedOthers = [];
  foreach ($allOthersList as $item) {
      $key = strtolower(trim($item['label']));
      if (!isset($mergedOthers[$key])) {
          $mergedOthers[$key] = ['label' => $item['label'], 'amount' => 0];
      }
      $mergedOthers[$key]['amount'] += $item['amount'];
  }
  $accumulated['others_json'] = json_encode(array_values($mergedOthers));

  // ==========================================================
  // 🔥 BAGIAN INI KITA TEMBAK LANGSUNG (HARDCODE) 🔥
  // ==========================================================
  // Kita gak usah pake deteksi kolom. Langsung SELECT gaji, lembur.
  
  $qUser = $mysqli->query("SELECT nama_lengkap, lembur, gaji FROM users WHERE id = $user_id LIMIT 1");
  
  if ($u = $qUser->fetch_assoc()) {
      $accumulated['nama'] = $u['nama_lengkap'] ?? "User#$user_id";
      
      // Ambil Rate Lembur
      $accumulated['rate_per_jam'] = (int)($u['lembur'] ?? 10000);
      
      // 🔥 INI DIA: Ambil Gaji Harian langsung dari kolom 'gaji'
      $accumulated['gaji_pokok_harian'] = (int)($u['gaji'] ?? 0);
  }

  echo json_encode(["success"=>true, "data"=>[$accumulated]], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["success"=>false,"message"=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>