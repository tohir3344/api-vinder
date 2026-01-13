<?php
declare(strict_types=1);

// 1. Matikan error display agar output bersih JSON
ini_set('display_errors', '0');
error_reporting(0);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once __DIR__ . '/../Koneksi.php';
    
    $mysqli = $conn ?? $db;
    if (!$mysqli) { throw new Exception("Koneksi database tidak ditemukan."); }
    $mysqli->set_charset('utf8mb4');

    // 2. Ambil Input JSON
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true);

    if (!$in || !isset($in['user_id'])) { throw new Exception("Input tidak valid."); }

    // Variabel Input
    $user_id     = (int)$in['user_id'];
    $start       = trim((string)$in['start']);
    $end         = trim((string)$in['end']);
    $hadir       = (float)($in['hadir_minggu'] ?? 0);
    $telat       = (int)($in['total_telat'] ?? 0);
    
    // Ini nilai inputan Admin (300.000)
    $gp_harian   = (int)($in['gaji_pokok_harian'] ?? 0); 
    
    $gp_total    = (int)($in['gaji_pokok_rp'] ?? 0);
    $pot_telat   = (int)($in['potongan_telat_rp'] ?? 0);
    $angsuran    = (int)($in['angsuran_rp'] ?? 0); 
    $bonus       = (int)($in['bonus_bulanan_rp'] ?? 0);
    $total_final = (int)($in['total_gaji_final'] ?? 0);

    $mysqli->begin_transaction();

    // 3. Ambil data Lembur (Menit & Rupiah) agar sinkron
    $stL = $mysqli->prepare("SELECT SUM(total_menit) as m FROM lembur WHERE user_id=? AND tanggal BETWEEN ? AND ?");
    $stL->bind_param("iss", $user_id, $start, $end);
    $stL->execute();
    $resL = $stL->get_result()->fetch_assoc();
    $menit_lembur = (int)($resL['m'] ?? 0);

    // Ambil rate lembur dari user
    $stU = $mysqli->prepare("SELECT lembur FROM users WHERE id=?");
    $stU->bind_param("i", $user_id);
    $stU->execute();
    $uData = $stU->get_result()->fetch_assoc();
    $rate_lembur = (int)($uData['lembur'] ?? 10000);
    $lembur_rp = (int)round(($menit_lembur / 60) * $rate_lembur);

    // 4. Kelola Item Lainnya (others)
    $others = $in['others'] ?? [];
    $others_total = 0;
    foreach ($others as $o) { $others_total += (int)($o['amount'] ?? 0); }
    $others_json = json_encode($others, JSON_UNESCAPED_UNICODE);

    // 5. Simpan ke tabel gaji_run (Data Transaksi/History)
    $sql = "INSERT INTO gaji_run (
                user_id, periode_start, periode_end, hadir_minggu, total_telat, 
                lembur_menit, lembur_rp, gaji_pokok_rp, angsuran_rp, 
                potongan_telat_rp, bonus_bulanan_rp, others_total_rp, 
                others_json, total_gaji_rp, status_bayar, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ON DUPLICATE KEY UPDATE 
                hadir_minggu = VALUES(hadir_minggu), total_telat = VALUES(total_telat),
                lembur_rp = VALUES(lembur_rp), total_gaji_rp = VALUES(total_gaji_rp),
                others_json = VALUES(others_json)";

    $stmt = $mysqli->prepare($sql);
    $types = "issdiiiiiiiisi"; 
    $stmt->bind_param($types, $user_id, $start, $end, $hadir, $telat, $menit_lembur, $lembur_rp, $gp_total, $angsuran, $pot_telat, $bonus, $others_total, $others_json, $total_final);
    
    if (!$stmt->execute()) { throw new Exception("Eksekusi query gagal: " . $stmt->error); }

    // ============================================================
    // 🔥 FIX: UPDATE MASTER GAJI DI TABEL USERS 🔥
    // ============================================================
    // Menggunakan nama kolom 'gaji' sesuai database kamu (bukan gaji_pokok)
    if ($gp_harian > 0) {
        $updMaster = $mysqli->prepare("UPDATE users SET gaji = ? WHERE id = ?");
        $updMaster->bind_param("ii", $gp_harian, $user_id);
        $updMaster->execute();
    }
    // ============================================================

    // 6. UPDATE ANGSURAN & CATAT KE TABEL angsuran_potongan
    $pesan_angsuran = "Tidak ada angsuran dipotong.";
    
    if ($angsuran > 0) {
        $cekAng = $mysqli->prepare("SELECT id, sisa FROM angsuran WHERE user_id = ? AND sisa > 0 ORDER BY id ASC LIMIT 1");
        $cekAng->bind_param("i", $user_id);
        $cekAng->execute();
        $resAng = $cekAng->get_result()->fetch_assoc();

        if ($resAng) {
            $id_angsuran = (int)$resAng['id'];
            $sisa_lama   = (int)$resAng['sisa'];
            
            $sisa_baru = $sisa_lama - $angsuran;
            if ($sisa_baru < 0) $sisa_baru = 0;
            $status_baru = ($sisa_baru === 0) ? 'lunas' : 'disetujui';

            $updAng = $mysqli->prepare("UPDATE angsuran SET sisa = ?, status = ? WHERE id = ?");
            $updAng->bind_param("isi", $sisa_baru, $status_baru, $id_angsuran);
            
            if ($updAng->execute()) {
                $insHist = $mysqli->prepare("INSERT INTO angsuran_potongan (angsuran_id, tanggal_potong, potongan, sisa_setelah) VALUES (?, NOW(), ?, ?)");
                $insHist->bind_param("iii", $id_angsuran, $angsuran, $sisa_baru);
                $insHist->execute();
                
                $pesan_angsuran = "Angsuran Rp " . number_format($angsuran) . " berhasil dipotong & dicatat.";
            }
        }
    }

    $mysqli->commit();

    echo json_encode([
        "success" => true,
        "message" => "Slip Tersimpan. Gaji Master Diupdate. " . $pesan_angsuran
    ]);

} catch (Throwable $e) {
    if (isset($mysqli) && $mysqli->connect_errno === 0) { $mysqli->rollback(); }
    http_response_code(200); 
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
?>