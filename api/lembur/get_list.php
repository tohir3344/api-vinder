<?php
// api/lembur/get_list.php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../Koneksi.php';

// Fungsi bantu hitung upah per baris (Hanya dipakai jika DB kosong)
function hitungUpahBaris($tanggal, $jam_masuk, $jam_keluar, $rate_lembur) {
    if (empty($jam_masuk) || empty($jam_keluar)) return 0;
    try {
        $startL = new DateTime($tanggal . ' ' . $jam_masuk);
        $endL   = new DateTime($tanggal . ' ' . $jam_keluar);
        if ($endL < $startL) $endL->modify('+1 day');

        $batasBawah  = new DateTime($tanggal . ' 17:00:00'); 
        $batasDouble = new DateTime($tanggal . ' 20:00:00');

        if ($startL < $batasBawah) $startL = clone $batasBawah; 
        if ($endL <= $startL) return 0;

        $menit = ($endL->getTimestamp() - $startL->getTimestamp()) / 60;
        if ($menit <= 0) return 0;

        $upah = 0;
        if ($endL <= $batasDouble) {
            $jam = $menit / 60;
            $upah += ($jam * $rate_lembur);
        } elseif ($startL >= $batasDouble) {
            $jam = $menit / 60;
            $upah += ($jam * $rate_lembur * 2);
        } else {
            $secsNormal = $batasDouble->getTimestamp() - $startL->getTimestamp();
            $jamNormal  = max(0, ($secsNormal / 60) / 60);
            $secsDouble = $endL->getTimestamp() - $batasDouble->getTimestamp();
            $jamDouble  = max(0, ($secsDouble / 60) / 60);
            $upah += ($jamNormal * $rate_lembur);
            $upah += ($jamDouble * $rate_lembur * 2);
        }
        return (int)ceil($upah); 
    } catch (Exception $e) { return 0; }
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
  else if ($start)    { $where[]="(l.tanggal >= ?)";            $params[]=$start;                   $types.="s";  }
  else if ($end)      { $where[]="(l.tanggal <= ?)";            $params[]=$end;                     $types.="s";  }
  else                { $where[]="(l.tanggal >= CURRENT_DATE - INTERVAL 30 DAY)"; }

  $whereSql = "WHERE ".implode(" AND ", $where);

  // 🔥 PERBAIKAN 1: Tambahkan l.status dan l.jenis_lembur di query SQL
  $sql = "
    SELECT
      l.id, l.user_id, l.tanggal, l.jam_masuk, l.jam_keluar, l.alasan, l.alasan_keluar,
      l.total_menit, l.total_upah, l.status, l.jenis_lembur
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
    
    // 🔥 PERBAIKAN 2: PRIORITAS UPAH DARI DATABASE
    // Jika Admin sudah input (total_upah > 0), pakai itu. Jangan hitung ulang.
    $upah_final = 0;
    if (!empty($r['total_upah']) && $r['total_upah'] > 0) {
        $upah_final = (int)$r['total_upah'];
    } else {
        // Jika DB 0 (otomatis), baru hitung pakai rumus live
        $upah_final = hitungUpahBaris($r['tanggal'], $r['jam_masuk'], $r['jam_keluar'], $rate_lembur_user);
    }

    // 🔥 PERBAIKAN 3: STATUS & JENIS
    // Pastikan status ada isinya (default pending)
    $status_final = !empty($r['status']) ? strtolower($r['status']) : 'pending';
    
    // Ambil jenis dari DB dulu, kalau kosong baru cek jam
    $jenis_final = !empty($r['jenis_lembur']) ? strtolower($r['jenis_lembur']) : 'biasa';
    if (empty($r['jenis_lembur']) && !empty($r['jam_keluar'])) {
         // Fallback logic jika DB kosong
         $jamKeluarObj = new DateTime($r['tanggal'] . ' ' . $r['jam_keluar']);
         $batasOver    = new DateTime($r['tanggal'] . ' 20:00:00');
         if ($jamKeluarObj > $batasOver) $jenis_final = 'over';
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
      "alasan_masuk"   => $r["alasan"], 
      "alasan_keluar"  => $r["alasan_keluar"],
      
      "total_menit"    => $total_menit,
      "total_jam"      => $total_jam_str,
      
      "total_upah"     => $upah_final, // Pakai upah yg sudah difix
      "jenis_lembur"   => $jenis_final, 
      "status"         => $status_final, // 🔥 PENTING: Dikirim ke frontend
      "rate_used"      => $rate_lembur_user
    ];
  }

  // --- Summary (Opsional, dibiarkan hitung live untuk estimasi) ---
  // Kalau mau akurat, harusnya query SUM(total_upah) dari DB, tapi logic ini oke untuk estimasi.
  $stmt2 = $conn->prepare("
    SELECT tanggal, jam_masuk, jam_keluar, total_upah 
    FROM lembur 
    WHERE user_id = ? AND tanggal >= CURRENT_DATE - INTERVAL 6 DAY
  ");
  $stmt2->bind_param("i", $user_id);
  $stmt2->execute();
  $resSummary = $stmt2->get_result();

  $sum_upah = 0;
  while($s = $resSummary->fetch_assoc()) {
      if ($s['total_upah'] > 0) {
          $sum_upah += (int)$s['total_upah'];
      } else {
          $sum_upah += hitungUpahBaris($s['tanggal'], $s['jam_masuk'], $s['jam_keluar'], $rate_lembur_user);
      }
  }

  echo json_encode([
    "success" => true,
    "count"   => count($rows),
    "data"    => $rows, 
    "rows"    => $rows, 
    "summary" => [
      "total_upah_7hari" => $sum_upah,
      "info"             => "Data synced with Admin Input"
    ]
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(["success"=>false, "message"=>$e->getMessage()]);
}
?>