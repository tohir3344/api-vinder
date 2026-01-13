<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../php_error.log');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

header("Content-Type: application/json; charset=utf-8");

ini_set('display_errors', '0');
// error_reporting(EALL);

try {
    // ==== KONEKSI ====
    $connPath = __DIR__ . '/../Koneksi.php'; // ganti ke 'koneksi.php' jika perlu
    if (!file_exists($connPath)) {
        http_response_code(500);
        echo json_encode(["success"=>false, "message"=>"Koneksi.php not found (check filename case)."]);
        exit;
    }
    require_once $connPath;

    // ==== PARAMS ====
    $range = (isset($_GET['range']) && $_GET['range'] === 'month') ? 'month' : 'week';
    $endDate   = date('Y-m-d'); // hari ini
    $startDate = $range === 'month' ? date('Y-m-d', strtotime('-30 days'))
                                    : date('Y-m-d', strtotime('-7 days'));

    // ==== TOTALS PER STATUS ====
    $totals = ["hadir"=>0, "izin"=>0, "sakit"=>0, "alpha"=>0];

    $sqlTotals = "
      SELECT
        SUM(CASE WHEN a.status='HADIR' THEN 1 ELSE 0 END) AS hadir,
        SUM(CASE WHEN a.status='IZIN'  THEN 1 ELSE 0 END) AS izin,
        SUM(CASE WHEN a.status='SAKIT' THEN 1 ELSE 0 END) AS sakit,
        SUM(CASE WHEN a.status='ALPHA' THEN 1 ELSE 0 END) AS alpha
      FROM absen a
      WHERE a.tanggal BETWEEN ? AND ?
    ";
    $stmt = $conn->prepare($sqlTotals);
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r && ($x = $r->fetch_assoc())) {
        $totals["hadir"] = (int)($x["hadir"] ?? 0);
        $totals["izin"]  = (int)($x["izin"]  ?? 0);
        $totals["sakit"] = (int)($x["sakit"] ?? 0);
        $totals["alpha"] = (int)($x["alpha"] ?? 0);
    }
    $stmt->close();

    // ==== DAILY BREAKDOWN ====
    $daily = [];
    $sqlDaily = "
      SELECT
        a.tanggal,
        SUM(CASE WHEN a.status='HADIR' THEN 1 ELSE 0 END) AS hadir,
        SUM(CASE WHEN a.status='IZIN'  THEN 1 ELSE 0 END) AS izin,
        SUM(CASE WHEN a.status='SAKIT' THEN 1 ELSE 0 END) AS sakit,
        SUM(CASE WHEN a.status='ALPHA' THEN 1 ELSE 0 END) AS alpha
      FROM absen a
      WHERE a.tanggal BETWEEN ? AND ?
      GROUP BY a.tanggal
      ORDER BY a.tanggal ASC
    ";
    $stmt2 = $conn->prepare($sqlDaily);
    $stmt2->bind_param('ss', $startDate, $endDate);
    $stmt2->execute();
    $r2 = $stmt2->get_result();
    while ($d = $r2->fetch_assoc()) {
        $daily[] = [
            "tanggal" => $d["tanggal"],
            "hadir"   => (int)($d["hadir"] ?? 0),
            "izin"    => (int)($d["izin"]  ?? 0),
            "sakit"   => (int)($d["sakit"] ?? 0),
            "alpha"   => (int)($d["alpha"] ?? 0),
        ];
    }
    $stmt2->close();

    echo json_encode([
        "success" => true,
        "range"   => $range,
        "start"   => $startDate,
        "end"     => $endDate,
        "totals"  => $totals,
        "daily"   => $daily
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error"   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
