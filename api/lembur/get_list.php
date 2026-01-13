<?php
// api/lembur/get_list.php
declare(strict_types=1);

// Matikan error display agar output JSON bersih
ini_set('display_errors', '0');
error_reporting(0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../Koneksi.php';

// Fungsi bantu hitung upah per baris (Rumus Baru)
function hitungUpahBaris($tanggal, $jam_masuk, $jam_keluar, $rate_lembur) {
    if (empty($jam_masuk) || empty($jam_keluar)) return 0;

    try {
        $startL = new DateTime($tanggal . ' ' . $jam_masuk);
        $endL   = new DateTime($tanggal . ' ' . $jam_keluar);
        
        // Handle lewat tengah malam
        if ($endL < $startL) $endL->modify('+1 day');

        // Batas Waktu
        $batasBawah  = new DateTime($tanggal . ' 17:00:00'); 
        $batasDouble = new DateTime($tanggal . ' 20:00:00');

        // Normalisasi Start (Mulai hitung 17:00)
        if ($startL < $batasBawah) $startL = clone $batasBawah; 

        // Jika pulang sebelum start (sebelum 17:00), 0 rupiah
        if ($endL <= $startL) return 0;

        // Hitung Menit
        $menit = ($endL->getTimestamp() - $startL->getTimestamp()) / 60;
        if ($menit <= 0) return 0;

        $upah = 0;

        // RUMUS SPLIT (17-20 Normal, >20 Double)
        if ($endL <= $batasDouble) {
            $jam = $menit / 60;
            $upah += ($jam * $rate_lembur);
        }
        elseif ($startL >= $batasDouble) {
            $jam = $menit / 60;
            $upah += ($jam * $rate_lembur * 2);
        }
        else {
            $secsNormal = $batasDouble->getTimestamp() - $startL->getTimestamp();
            $jamNormal  = max(0, ($secsNormal / 60) / 60);
            
            $secsDouble = $endL->getTimestamp() - $batasDouble->getTimestamp();
            $jamDouble  = max(0, ($secsDouble / 60) / 60);

            $upah += ($jamNormal * $rate_lembur);
            $upah += ($jamDouble * $rate_lembur * 2);
        }

        return (int)ceil($upah); 

    } catch (Exception $e) {
        return 0;
    }
}

try {
  $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
  if ($user_id <= 0) throw new Exception("user_id wajib");

  // 1. AMBIL TARIF USER TERBARU
  $qUser = $conn->prepare("SELECT lembur FROM users WHERE id=? LIMIT 1");
  $qUser->bind_param('i', $user_id);
  $qUser->execute();
  $u = $qUser->get_result()->fetch_assoc();
  $rate_lembur_user = (int)($u['lembur'] ?? 0); 

  // Filter Tanggal
  $start = $_GET['start'] ?? null; 
  $end   = $_GET['end']   ?? null;
  $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;

  $where  = ["l.user_id = ?"];
  $params = [$user_id];
  $types  = "i";

  if ($start && $end) { $where[]="(l.tanggal BETWEEN ? AND ?)"; $params[]=$start; $params[]=$end; $types.="ss"; }
  else if ($start)    { $where[]="(l.tanggal >= ?)";            $params[]=$start;                    $types.="s";  }
  else if ($end)      { $where[]="(l.tanggal <= ?)";            $params[]=$end;                      $types.="s";  }
  else                { $where[]="(l.tanggal >= CURRENT_DATE - INTERVAL 30 DAY)"; }

  $whereSql = "WHERE ".implode(" AND ", $where);

  // Query Utama
  $sql = "
    SELECT
      l.id, l.user_id, l.tanggal, l.jam_masuk, l.jam_keluar, l.alasan, l.alasan_keluar,
      l.total_menit, l.total_upah
    FROM lembur l
    $whereSql
    ORDER BY l.tanggal DESC, l.id DESC
    LIMIT $limit
  ";

  $stmt = $conn->prepare($sql);
  if ($types !== "") $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();

  $rows = [];
  while ($r = $res->fetch_assoc()) {
    
    // --- LIVE CALCULATION ---
    $upah_realtime = hitungUpahBaris($r['tanggal'], $r['jam_masuk'], $r['jam_keluar'], $rate_lembur_user);
    
    // --- CEK APAKAH JENISNYA OVER (Pulang > 20:00) ---
    $isOver = false;
    if (!empty($r['jam_keluar'])) {
        $jamKeluarObj = new DateTime($r['tanggal'] . ' ' . $r['jam_keluar']);
        $batasOver    = new DateTime($r['tanggal'] . ' 20:00:00');
        if ($jamKeluarObj > $batasOver) {
            $isOver = true;
        }
    }

    $total_menit = (int)$r['total_menit'];
    $h = intdiv($total_menit, 60);
    $m = $total_menit % 60;
    $total_jam_str = $h . ":" . str_pad((string)$m, 2, "0", STR_PAD_LEFT);

    $rows[] = [
      "id"             => (int)$r["id"],
      "user_id"        => (int)$r["user_id"],
      "tanggal"        => $r["tanggal"],
      "jam_masuk"      => $r["jam_masuk"],
      "jam_keluar"     => $r["jam_keluar"],
      "alasan_masuk"   => $r["alasan"], // Disesuaikan dengan frontend
      "alasan_keluar"  => $r["alasan_keluar"],
      
      "total_menit"    => $total_menit,
      "total_jam"      => $total_jam_str,
      
      "total_upah"     => $upah_realtime, 
      "jenis_lembur"   => $isOver ? "over" : "biasa", // Field baru untuk Frontend
      "rate_used"      => $rate_lembur_user
    ];
  }

  // --- HITUNG SUMMARY 7 HARI TERAKHIR ---
  $stmt2 = $conn->prepare("
    SELECT tanggal, jam_masuk, jam_keluar 
    FROM lembur 
    WHERE user_id = ? AND tanggal >= CURRENT_DATE - INTERVAL 6 DAY
  ");
  $stmt2->bind_param("i", $user_id);
  $stmt2->execute();
  $resSummary = $stmt2->get_result();

  $sum_upah = 0;
  while($s = $resSummary->fetch_assoc()) {
      $sum_upah += hitungUpahBaris($s['tanggal'], $s['jam_masuk'], $s['jam_keluar'], $rate_lembur_user);
  }

  echo json_encode([
    "success" => true,
    "count"   => count($rows),
    "data"    => $rows, // React Native biasanya baca field 'data'
    "rows"    => $rows, 

    "summary" => [
      "total_upah_7hari" => $sum_upah,
      "info"             => "Realtime Calculation (17-20 x1, >20 x2)"
    ]
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(["success"=>false, "message"=>$e->getMessage()]);
}
?>