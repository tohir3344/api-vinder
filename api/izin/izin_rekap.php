<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../Koneksi.php'; // sesuaikan path koneksi
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Endpoint ini mendukung dua mode:
 *  - mode=weekly  → rekap minggu berjalan (Senin 00:00 s.d Minggu 23:59).
 *  - mode=monthly → rekap per bulan. Param opsional: year=YYYY, month=1..12 (default: bulan ini).
 *
 * Output:
 * {
 *   success: true,
 *   meta: { mode, range: {start,end}, year, month },
 *   by_user: [
 *     { user_id, username, total, pending, disetujui, ditolak }
 *   ],
 *   entries: [ baris izin mentah (opsional untuk ditampilkan rinci) ]
 * }
 */

try {
  $mode  = strtolower(trim((string)($_GET['mode'] ?? 'weekly')));
  $qUser = trim((string)($_GET['q'] ?? '')); // optional: filter username
  $withEntries = (($_GET['entries'] ?? '0') === '1'); // kirim detail entries juga

  // Hitung range waktu
  $start = null; $end = null;
  $meta = ['mode' => $mode];

  if ($mode === 'monthly') {
    $year  = (int)($_GET['year'] ?? (int)date('Y'));
    $month = (int)($_GET['month'] ?? (int)date('n')); // 1..12

    if ($month < 1 || $month > 12) { throw new Exception("month invalid"); }
    if ($year < 1970 || $year > 2100) { throw new Exception("year invalid"); }

    $startDt = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), new DateTimeZone('Asia/Jakarta'));
    $endDt   = $startDt->modify('first day of next month')->modify('-1 second'); // sampai 23:59:59 hari terakhir

    $start = $startDt->format('Y-m-d 00:00:00');
    $end   = $endDt->format('Y-m-d 23:59:59');

    $meta['year']  = $year;
    $meta['month'] = $month;
  } else {
    // default: WEEKLY (Senin 00:00 → Minggu 23:59)
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    // "monday this week" di PHP: jika hari Minggu, dia balik ke Senin 6 hari lalu → cocok.
    $monday = new DateTimeImmutable('monday this week 00:00:00', new DateTimeZone('Asia/Jakarta'));
    $sunday = $monday->modify('sunday this week')->setTime(23,59,59);

    $start = $monday->format('Y-m-d H:i:s');
    $end   = $sunday->format('Y-m-d H:i:s');
  }

  $meta['range'] = ['start' => $start, 'end' => $end];

  // ===== Query agregasi by_user =====
  $sqlAgg = "
    SELECT
      i.user_id,
      u.username,
      COUNT(*) AS total,
      SUM(CASE WHEN i.status='pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN i.status='disetujui' THEN 1 ELSE 0 END) AS disetujui,
      SUM(CASE WHEN i.status='ditolak' THEN 1 ELSE 0 END) AS ditolak
    FROM izin i
    JOIN users u ON u.id = i.user_id
    WHERE CONCAT(i.tanggal_mulai, ' 00:00:00') <= ? 
      AND CONCAT(i.tanggal_selesai, ' 23:59:59') >= ?
  ";
  $typesAgg = 'ss';
  $paramsAgg = [$end, $start];

  if ($qUser !== '') {
    $sqlAgg .= " AND u.username LIKE CONCAT('%', ?, '%') ";
    $typesAgg .= 's';
    $paramsAgg[] = $qUser;
  }

  $sqlAgg .= " GROUP BY i.user_id, u.username
               ORDER BY disetujui DESC, pending DESC, total DESC, u.username ASC";

  $stmtAgg = $conn->prepare($sqlAgg);
  $stmtAgg->bind_param($typesAgg, ...$paramsAgg);
  $stmtAgg->execute();
  $resAgg = $stmtAgg->get_result();

  $byUser = [];
  while ($r = $resAgg->fetch_assoc()) {
    $byUser[] = [
      'user_id'   => (int)$r['user_id'],
      'username'  => $r['username'],
      'total'     => (int)$r['total'],
      'pending'   => (int)$r['pending'],
      'disetujui' => (int)$r['disetujui'],
      'ditolak'   => (int)$r['ditolak'],
    ];
  }

  // ===== (Opsional) kirim daftar entries rinci dalam range =====
  $entries = [];
  if ($withEntries) {
    $sqlEnt = "
      SELECT i.id, i.user_id, u.username, i.keterangan, i.alasan,
             i.tanggal_mulai, i.tanggal_selesai, i.status, i.created_at
      FROM izin i
      JOIN users u ON u.id = i.user_id
      WHERE CONCAT(i.tanggal_mulai, ' 00:00:00') <= ?
        AND CONCAT(i.tanggal_selesai, ' 23:59:59') >= ?
    ";
    $typesEnt = 'ss';
    $paramsEnt = [$end, $start];

    if ($qUser !== '') {
      $sqlEnt .= " AND u.username LIKE CONCAT('%', ?, '%') ";
      $typesEnt .= 's';
      $paramsEnt[] = $qUser;
    }

    $sqlEnt .= " ORDER BY i.tanggal_mulai ASC, i.tanggal_selesai ASC, i.id ASC";

    $stmtEnt = $conn->prepare($sqlEnt);
    $stmtEnt->bind_param($typesEnt, ...$paramsEnt);
    $stmtEnt->execute();
    $resEnt = $stmtEnt->get_result();

    while ($e = $resEnt->fetch_assoc()) {
      $entries[] = [
        'id'         => (int)$e['id'],
        'user_id'    => (int)$e['user_id'],
        'username'   => $e['username'],
        'keterangan' => $e['keterangan'],
        'alasan'     => $e['alasan'],
        'tanggal_mulai'      => $e['tanggal_mulai'],
        'tanggal_selesai'    => $e['tanggal_selesai'],
        'status'     => $e['status'],
        'created_at' => $e['created_at'],
      ];
    }
  }

  echo json_encode([
    'success' => true,
    'meta'    => $meta,
    'by_user' => $byUser,
    'entries' => $entries, // kosong jika entries=0
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
