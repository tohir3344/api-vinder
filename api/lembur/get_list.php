<?php
declare(strict_types=1);
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../Koneksi.php';
require_once __DIR__ . '/config_lembur.php'; // ⬅️ tambahkan ini

try {
  $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
  if ($user_id <= 0) throw new Exception("user_id wajib");

  $start = $_GET['start'] ?? null;  // YYYY-MM-DD
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

  /**
   * NORMALISASI JAM:
   * - kosong ('') -> NULL
   * - titik diganti titik dua (08.15 -> 08:15)
   * - cast ke TIME()
   */
  $jam_masuk_time  = "TIME(REPLACE(NULLIF(l.jam_masuk,''),  '.', ':'))";
  $jam_keluar_time = "TIME(REPLACE(NULLIF(l.jam_keluar,''), '.', ':'))";

  // Pakai cutoff dari SSoT
  $cut_in  = "TIME '".LE_START_CUTOFF."'";
  $cut_out = "TIME '".LE_END_CUTOFF."'";

  // menit lembur split (masuk & keluar)
  $expr_masuk = "
    CASE
      WHEN $jam_masuk_time IS NOT NULL AND $jam_masuk_time < $cut_in
        THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, $jam_masuk_time, $cut_in))
      ELSE 0
    END
  ";
  $expr_keluar = "
    CASE
      WHEN $jam_keluar_time IS NOT NULL AND $jam_keluar_time > $cut_out
        THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, $cut_out, $jam_keluar_time))
      ELSE 0
    END
  ";
  $expr_total = "($expr_masuk + $expr_keluar)";

  $sql = "
    SELECT
      l.id, l.user_id, l.tanggal, l.jam_masuk, l.jam_keluar, l.alasan,

      -- split menit (fallback kalkulasi on-the-fly)
      $expr_masuk  AS menit_masuk_calc,
      $expr_keluar AS menit_keluar_calc,

      -- total menit (pakai tersimpan kalau ada; else kalkulasi)
      CASE
        WHEN COALESCE(l.total_menit,0) > 0 THEN l.total_menit
        ELSE $expr_total
      END AS total_menit_final,

      -- upah dihitung per MENIT, bukan dibulatkan per jam
      CASE
        WHEN COALESCE(l.total_upah,0) > 0 THEN l.total_upah
        ELSE ROUND(($expr_total) * ".(LE_RATE_PER_MENIT).")
      END AS total_upah_final

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
    // kalau punya kolom split di DB, pakai jika > 0; else pakai kalkulasi
    $menit_masuk  = isset($r['total_menit_masuk']) && (int)$r['total_menit_masuk'] > 0
                  ? (int)$r['total_menit_masuk']
                  : (int)$r['menit_masuk_calc'];

    $menit_keluar = isset($r['total_menit_keluar']) && (int)$r['total_menit_keluar'] > 0
                  ? (int)$r['total_menit_keluar']
                  : (int)$r['menit_keluar_calc'];

    $total_menit  = (int)$r['total_menit_final'];
    $h = intdiv($total_menit, 60);
    $m = $total_menit % 60;
    $total_jam_str = $h . ":" . str_pad((string)$m, 2, "0", STR_PAD_LEFT);

    $rows[] = [
      "id"                 => (int)$r["id"],
      "user_id"            => (int)$r["user_id"],
      "tanggal"            => $r["tanggal"],
      "jam_masuk"          => $r["jam_masuk"],
      "jam_keluar"         => $r["jam_keluar"],
      "alasan"             => $r["alasan"],

      "total_menit_masuk"  => $menit_masuk,
      "total_menit_keluar" => $menit_keluar,
      "total_menit"        => $total_menit,
      "total_jam"          => $total_jam_str,
      "total_upah"         => (int)$r["total_upah_final"],

      "rate_per_menit"     => LE_RATE_PER_MENIT,
    ];
  }

  // Summary 7 hari (opsional, buat UI ringkas)
  $stmt2 = $conn->prepare("
    SELECT SUM($expr_masuk) AS sum_in, SUM($expr_keluar) AS sum_out
    FROM lembur l
    WHERE l.user_id = ? AND l.tanggal >= CURRENT_DATE - INTERVAL 6 DAY
  ");
  $stmt2->bind_param("i", $user_id);
  $stmt2->execute();
  $sumRow = $stmt2->get_result()->fetch_assoc();
  $sum_in  = (int)($sumRow["sum_in"]  ?? 0);
  $sum_out = (int)($sumRow["sum_out"] ?? 0);
  $sum_total = $sum_in + $sum_out;
  $sum_upah  = (int) round($sum_total * LE_RATE_PER_MENIT);

  echo json_encode([
    "success" => true,
    "count"   => count($rows),
    "rows"    => $rows,
    "data"    => $rows, // alias untuk kompatibilitas

    "summary" => [
      "menit_masuk_7hari"  => $sum_in,
      "menit_keluar_7hari" => $sum_out,
      "total_menit_7hari"  => $sum_total,
      "total_upah_7hari"   => $sum_upah,
      "rate_per_jam"       => LE_RATE_PER_JAM,
      "rate_per_menit"     => LE_RATE_PER_MENIT,
      "cutoff_start"       => LE_START_CUTOFF,
      "cutoff_end"         => LE_END_CUTOFF,
    ]
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(["success"=>false, "message"=>$e->getMessage()]);
}
