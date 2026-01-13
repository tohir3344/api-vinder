<?php
declare(strict_types=1);

/*
 * GET  : angsuran/riwayat.php?angsuran_id=123
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ===== KONEKSI =====
require_once __DIR__ . '/../Koneksi.php';

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    if (isset($conn) && $conn instanceof mysqli) $mysqli = $conn;
    elseif (isset($db) && $db instanceof mysqli) $mysqli = $db;
    else {
        $mysqli = new mysqli("localhost", "root", "", "penggajian_db");
    }
}
$mysqli->set_charset("utf8mb4");

function ok($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function bad($msg, $http = 400) {
    http_response_code($http);
    echo json_encode(["success" => false, "message" => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!isset($_GET['angsuran_id'])) bad("Parameter 'angsuran_id' wajib diisi.");
    $angsuranId = (int)$_GET['angsuran_id'];

    // 1. Ambil Nominal Awal Angsuran Induk
    $stmt = $mysqli->prepare("SELECT nominal FROM angsuran WHERE id = ?");
    $stmt->bind_param("i", $angsuranId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ang = $res->fetch_assoc();
    $stmt->close();

    if (!$ang) bad("Data angsuran tidak ditemukan.", 404);
    $nominalAwal = (float)$ang['nominal'];

    // 2. Deteksi Kolom Tanggal di Table 'angsuran_potongan'
    // Sesuai screenshot Boss, kolomnya 'tanggal_potong'
    $cols = [];
    $qCols = $mysqli->query("SHOW COLUMNS FROM angsuran_potongan");
    while ($row = $qCols->fetch_assoc()) {
        $cols[] = strtolower($row['Field']);
    }

    // Tentukan prioritas kolom
    $colTgl = "NULL";
    if (in_array('tanggal_potong', $cols)) {
        $colTgl = "tanggal_potong"; // ✅ PRIORITAS UTAMA (Sesuai Screenshot DB)
    } elseif (in_array('created_at', $cols)) {
        $colTgl = "created_at";
    } elseif (in_array('tanggal', $cols)) {
        $colTgl = "tanggal";
    }

    // 3. Query Riwayat
    // Kita select kolom tanggal yang sudah dideteksi tadi sebagai alias 'tgl_transaksi'
    $sql = "SELECT id, potongan, $colTgl AS tgl_transaksi 
            FROM angsuran_potongan 
            WHERE angsuran_id = ? 
            ORDER BY id ASC"; // Urut ID ASC biar sisa hitungannya runtut

    $stmt2 = $mysqli->prepare($sql);
    $stmt2->bind_param("i", $angsuranId);
    $stmt2->execute();
    $res2 = $stmt2->get_result();

    $riwayat = [];
    $totalPotongan = 0.0;

    while ($row = $res2->fetch_assoc()) {
        $potongan = (float)$row['potongan'];
        $totalPotongan += $potongan;
        
        // Hitung sisa berjalan (biar valid sesuai urutan)
        $sisaBerjalan = $nominalAwal - $totalPotongan;
        if ($sisaBerjalan < 0) $sisaBerjalan = 0;

        // Ambil tanggal
        $rawTgl = $row['tgl_transaksi'];
        
        // Format tanggal biar bersih (YYYY-MM-DD)
        if (!empty($rawTgl)) {
            $finalTgl = substr((string)$rawTgl, 0, 10);
        } else {
            $finalTgl = date('Y-m-d'); // Fallback kalau kosong
        }

        $riwayat[] = [
            "id"       => (int)$row['id'],
            "tanggal"  => $finalTgl, // Ini sudah pasti tanggal_potong
            "potongan" => $potongan,
            "sisa"     => $sisaBerjalan
        ];
    }
    $stmt2->close();

    // Kirim ke Frontend
    ok($riwayat);

} catch (Exception $e) {
    bad("Server Error: " . $e->getMessage(), 500);
}
?>