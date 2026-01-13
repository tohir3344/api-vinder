<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ====== Koneksi ====== */
$conn = null;
try {
    require_once __DIR__ . '/../Koneksi.php'; // perbaiki _DIR_
} catch (Throwable $e) {
    // fallback; SESUAIKAN kredensial
    $conn = new mysqli("localhost", "root", "", "penggajian_db");
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli)      $conn = $mysqli;
    elseif (isset($db) && $db instanceof mysqli)          $conn = $db;
}
if (!($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(["success"=>false, "message"=>"Koneksi DB tidak tersedia"]);
    exit;
}
$conn->set_charset("utf8mb4");

/* ====== Helper input ====== */
function read_input(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    $raw = file_get_contents('php://input') ?: '';

    // JSON
    if (stripos($ct, 'application/json') !== false) {
        $j = json_decode($raw, true);
        if (is_array($j)) return $j;
    }

    // Form-url-encoded
    if (stripos($ct, 'application/x-www-form-urlencoded') !== false) {
        return $_POST ?: [];
    }

    // Fallback: try parse raw to array
    $arr = [];
    parse_str($raw, $arr);
    if (is_array($arr) && !empty($arr)) return $arr;

    // Terakhir: gabung GET (kalau ada)
    return $_GET ?: [];
}

/* ====== Baca & Validasi ====== */
$input = read_input();

$angsuran_id = isset($input['angsuran_id']) ? (int)$input['angsuran_id'] : 0;
$potongan    = isset($input['potongan']) ? (float)$input['potongan'] : 0.0;
// tanggal opsional (YYYY-MM-DD). Jika kosong, pakai hari ini
$tanggal     = isset($input['tanggal']) ? trim((string)$input['tanggal']) : date('Y-m-d');

if ($angsuran_id <= 0) {
    echo json_encode(["success"=>false, "message"=>"Parameter 'angsuran_id' wajib diisi."]);
    exit;
}
if ($potongan <= 0) {
    echo json_encode(["success"=>false, "message"=>"Nominal potongan harus > 0"]);
    exit;
}

/* ====== Transaksi ====== */
$conn->begin_transaction();
try {
    // Kunci baris angsuran
    $st = $conn->prepare("SELECT sisa FROM angsuran WHERE id = ? FOR UPDATE");
    $st->bind_param("i", $angsuran_id);
    $st->execute();
    $rs = $st->get_result();
    if ($rs->num_rows === 0) {
        $conn->rollback();
        echo json_encode(["success"=>false, "message"=>"Data angsuran tidak ditemukan"]);
        exit;
    }
    $row = $rs->fetch_assoc();
    $sisa_lama = (float)$row['sisa'];

    if ($sisa_lama <= 0) {
        $conn->rollback();
        echo json_encode(["success"=>false, "message"=>"Angsuran sudah lunas"]);
        exit;
    }
    if ($potongan > $sisa_lama) {
        $conn->rollback();
        echo json_encode(["success"=>false, "message"=>"Potongan melebihi sisa angsuran"]);
        exit;
    }

    $sisa_baru = $sisa_lama - $potongan;
    if ($sisa_baru < 0 && abs($sisa_baru) < 1e-6) $sisa_baru = 0.0;
    if ($sisa_baru < 0) $sisa_baru = 0.0;

    // Update sisa
    $up = $conn->prepare("UPDATE angsuran SET sisa = ? WHERE id = ?");
    $up->bind_param("di", $sisa_baru, $angsuran_id);
    if (!$up->execute()) throw new Exception("Gagal memperbarui sisa angsuran");

    // Insert riwayat: tabel kamu pakai tanggal_potong & sisa_setelah
    $in = $conn->prepare("INSERT INTO angsuran_potongan (angsuran_id, tanggal_potong, potongan, sisa_setelah) VALUES (?, ?, ?, ?)");
    $in->bind_param("isdd", $angsuran_id, $tanggal, $potongan, $sisa_baru);
    if (!$in->execute()) throw new Exception("Gagal menyimpan riwayat potongan");

    $conn->commit();

    echo json_encode([
        "success"=>true,
        "message"=>($sisa_baru == 0.0 ? "Potongan berhasil disimpan. Angsuran sudah lunas 🎉" : "Potongan berhasil disimpan"),
        "data"=>[
            "angsuran_id"=>$angsuran_id,
            "potongan"=>$potongan,
            "sisa_baru"=>$sisa_baru,
            "tanggal"=>$tanggal
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(["success"=>false, "message"=>$e->getMessage()]);
}