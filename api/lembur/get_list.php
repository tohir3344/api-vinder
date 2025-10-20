<?php
declare(strict_types=1);
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../Koneksi.php';

/** ====== KONFIGURASI (samakan dengan upsert.php) ====== */
const LEMBUR_START_CUTOFF = '10:00:00';
const LEMBUR_END_CUTOFF   = '17:20:00'; // samakan dengan upsert.php
const DEFAULT_RATE        = 10000;

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

  // ekspresi fallback hitung menit (pagi + sore)
  $calc_menit_expr = "
    (
      (CASE WHEN l.jam_masuk  IS NOT NULL AND l.jam_masuk  <  '".LEMBUR_START_CUTOFF."'
            THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, l.jam_masuk,  '".LEMBUR_START_CUTOFF."'))
            ELSE 0 END)
      +
      (CASE WHEN l.jam_keluar IS NOT NULL AND l.jam_keluar >= '".LEMBUR_END_CUTOFF."'
            THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, '".LEMBUR_END_CUTOFF."', l.jam_keluar))
            ELSE 0 END)
    )
  ";

  $sql = "
    SELECT
      l.id, l.user_id, l.tanggal, l.jam_masuk, l.jam_keluar, l.alasan,

      -- pakai kolom tersimpan kalau ada; kalau kosong hitung on-the-fly
      CASE
        WHEN COALESCE(l.total_menit,0) > 0 THEN l.total_menit
        ELSE $calc_menit_expr
      END AS total_menit_final,

      CASE
        WHEN COALESCE(l.total_jam,0) > 0 THEN l.total_jam
        ELSE FLOOR( ($calc_menit_expr) / 60 )
      END AS total_jam_final,

      CASE
        WHEN COALESCE(l.total_upah,0) > 0 THEN l.total_upah
        ELSE ( FLOOR( ($calc_menit_expr) / 60 ) * ".DEFAULT_RATE." )
      END AS total_upah_final

    FROM lembur l
    $whereSql
    ORDER BY l.tanggal DESC
    LIMIT $limit
  ";

  $stmt = $conn->prepare($sql);
  if ($types !== "") $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();

  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $rows[] = [
      "id"            => (int)$r["id"],
      "user_id"       => (int)$r["user_id"],
      "tanggal"       => $r["tanggal"],
      "jam_masuk"     => $r["jam_masuk"],
      "jam_keluar"    => $r["jam_keluar"],
      "alasan"        => $r["alasan"],
      "total_menit"   => (int)$r["total_menit_final"],
      "total_jam"     => (int)$r["total_jam_final"],
      "total_upah"    => (int)$r["total_upah_final"],
    ];
  }

  // ===== Summary 7 hari terakhir — pakai kolom tersimpan; fallback kalau kosong
  $stmt2 = $conn->prepare("
    SELECT
      SUM(
        CASE
          WHEN COALESCE(l.total_menit,0) > 0 THEN l.total_menit
          ELSE
            (
              (CASE WHEN l.jam_masuk  IS NOT NULL AND l.jam_masuk  <  '".LEMBUR_START_CUTOFF."'
                    THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, l.jam_masuk,  '".LEMBUR_START_CUTOFF."'))
                    ELSE 0 END)
              +
              (CASE WHEN l.jam_keluar IS NOT NULL AND l.jam_keluar >= '".LEMBUR_END_CUTOFF."'
                    THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, '".LEMBUR_END_CUTOFF."', l.jam_keluar))
                    ELSE 0 END)
            )
        END
      ) AS menit_minggu,

      SUM(
        CASE
          WHEN COALESCE(l.total_jam,0) > 0 THEN l.total_jam
          ELSE FLOOR(
            (
              (CASE WHEN l.jam_masuk  IS NOT NULL AND l.jam_masuk  <  '".LEMBUR_START_CUTOFF."'
                    THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, l.jam_masuk,  '".LEMBUR_START_CUTOFF."'))
                    ELSE 0 END)
              +
              (CASE WHEN l.jam_keluar IS NOT NULL AND l.jam_keluar >= '".LEMBUR_END_CUTOFF."'
                    THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, '".LEMBUR_END_CUTOFF."', l.jam_keluar))
                    ELSE 0 END)
            ) / 60
          )
        END
      ) AS jam_minggu,

      SUM(
        CASE
          WHEN COALESCE(l.total_upah,0) > 0 THEN l.total_upah
          ELSE (
            FLOOR(
              (
                (CASE WHEN l.jam_masuk  IS NOT NULL AND l.jam_masuk  <  '".LEMBUR_START_CUTOFF."'
                      THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, l.jam_masuk,  '".LEMBUR_START_CUTOFF."'))
                      ELSE 0 END)
                +
                (CASE WHEN l.jam_keluar IS NOT NULL AND l.jam_keluar >= '".LEMBUR_END_CUTOFF."'
                      THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, '".LEMBUR_END_CUTOFF."', l.jam_keluar))
                      ELSE 0 END)
              ) / 60
            ) * ".DEFAULT_RATE."
          )
        END
      ) AS upah_minggu
    FROM lembur l
    WHERE l.user_id = ?
      AND l.tanggal >= CURRENT_DATE - INTERVAL 6 DAY
  ");
  $stmt2->bind_param("i", $user_id);
  $stmt2->execute();
  $sumRow = $stmt2->get_result()->fetch_assoc();

  $menit_minggu = (int)($sumRow["menit_minggu"] ?? 0);
  $jam_minggu   = (int)($sumRow["jam_minggu"] ?? 0);
  $upah_minggu  = (int)($sumRow["upah_minggu"] ?? 0);

  echo json_encode([
    "success" => true,
    "count"   => count($rows),
    "data"    => $rows,
    "summary" => [
      "menit_minggu" => $menit_minggu,
      "jam_minggu"   => $jam_minggu,
      "upah_minggu"  => $upah_minggu,
      "rate"         => DEFAULT_RATE
    ]
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(["success"=>false, "message"=>$e->getMessage()]);
}
