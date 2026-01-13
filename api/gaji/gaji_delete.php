<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../Koneksi.php';

$mysqli = null;
if (isset($conn) && $conn instanceof mysqli) $mysqli = $conn;
elseif (isset($db) && $db instanceof mysqli) $mysqli = $db;

if (!$mysqli) {
    http_response_code(500);
    echo json_encode(["success"=>false,"message"=>"Koneksi DB Error"]);
    exit;
}
$mysqli->set_charset('utf8mb4');

// Helper buat cek kolom tabel
function table_has_col(mysqli $db, string $table, string $col): bool {
    try {
        $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
        return $res && $res->num_rows > 0;
    } catch (Exception $e) { return false; }
}

try {
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true) ?: [];
    $id = (int)($in['id'] ?? 0);

    if ($id <= 0) throw new Exception("ID Slip tidak valid");

    $mysqli->begin_transaction();

    // 1. AMBIL INFO SLIP SEBELUM DIHAPUS
    $stmt = $mysqli->prepare("SELECT user_id, angsuran_rp, periode_start FROM gaji_run WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $slip = $res->fetch_assoc();

    if (!$slip) throw new Exception("Slip Gaji tidak ditemukan");

    // 2. LOGIC REFUND (BALIKIN SALDO) KE ANGSURAN TERBARU (BY TANGGAL)
    if ($slip['angsuran_rp'] > 0) {
        $user_id = $slip['user_id'];
        $refund_rp = $slip['angsuran_rp'];

        // 🔥 FIX DISINI: Urutkan berdasarkan TANGGAL DESC, lalu ID DESC
        // Ini memastikan yang diambil benar-benar data paling akhir (18 Des), bukan 6 Des.
        $qHutang = $mysqli->prepare("
            SELECT id, sisa 
            FROM angsuran 
            WHERE user_id = ? 
            ORDER BY tanggal DESC, id DESC 
            LIMIT 1
        ");
        $qHutang->bind_param("i", $user_id);
        $qHutang->execute();
        $resHutang = $qHutang->get_result();

        if ($hut = $resHutang->fetch_assoc()) {
            $id_angsuran = $hut['id'];
            $sisa_sekarang = $hut['sisa'];
            
            // Balikin Saldo
            $sisa_baru = $sisa_sekarang + $refund_rp;
            
            // Update Sisa & Pastikan Status jadi 'disetujui'
            $updHutang = $mysqli->prepare("UPDATE angsuran SET sisa = ?, status = 'disetujui' WHERE id = ?");
            $updHutang->bind_param("ii", $sisa_baru, $id_angsuran);
            $updHutang->execute();

            // Hapus Log Riwayat Potongan (Opsional: Hapus log terakhir yang cocok nominalnya)
            if (table_has_col($mysqli, 'angsuran_potongan', 'potongan')) {
                // Hapus history potongan terakhir yg sesuai id angsuran
                $delLog = $mysqli->prepare("DELETE FROM angsuran_potongan WHERE angsuran_id = ? AND potongan = ? ORDER BY id DESC LIMIT 1");
                $delLog->bind_param("ii", $id_angsuran, $refund_rp);
                $delLog->execute();
            } 
            elseif (table_has_col($mysqli, 'riwayat_angsuran', 'potongan')) {
                $delLog = $mysqli->prepare("DELETE FROM riwayat_angsuran WHERE angsuran_id = ? AND potongan = ? ORDER BY id DESC LIMIT 1");
                $delLog->bind_param("ii", $id_angsuran, $refund_rp);
                $delLog->execute();
            }
        }
    }

    // 3. HAPUS SLIP GAJI
    $stmtDel = $mysqli->prepare("DELETE FROM gaji_run WHERE id = ?");
    $stmtDel->bind_param("i", $id);
    $stmtDel->execute();

    $mysqli->commit();

    echo json_encode(["success" => true, "message" => "Slip dihapus & saldo angsuran terbaru dikembalikan."]);

} catch (Exception $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
?>