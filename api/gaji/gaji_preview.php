<?php
// api/gaji/gaji_preview.php
declare(strict_types=1);

// 1. HEADER ANTI-CACHE
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

ini_set('display_errors', '0');
error_reporting(0);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';
$mysqli = $conn ?? $db;

// --- KONFIGURASI ---
const OFFICE_START_PREV = '08:00:00'; 
const PENALTY_TELAT = 20000;
const DEFAULT_ANGSURAN = 300000; // Kalau data kosong, pakai ini

try {
    if (!$mysqli) throw new Exception("Koneksi Database Gagal");

    $user_id = (int)($_GET['user_id'] ?? 0);
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;

    if ($user_id <= 0 || !$start || !$end) throw new Exception("Parameter wajib diisi.");

    // LOGIC TANGGAL
    $today_str = date('Y-m-d');
    $calc_end  = $end; 
    if ($end >= $today_str) {
        $calc_end = date('Y-m-d', strtotime('-1 day'));
    }
    $skip_calculation = ($start > $calc_end);

    // 1. DATA USER
    $stmt = $mysqli->prepare("SELECT nama_lengkap, gaji, lembur, role FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    if (!$u) throw new Exception("User tidak ditemukan.");

    $nama_karyawan = $u['nama_lengkap'];
    $nominal_gaji  = (int)($u['gaji'] ?? 0);
    $role          = strtolower($u['role'] ?? 'staff');

    // VAR HITUNGAN
    $hadir_poin = 0.0; $telat_count = 0; $potongan_telat_rp = 0;
    $total_menit_lembur = 0; $total_lembur_rp = 0; $bonus = 0;

    if (!$skip_calculation) {
        // 2. HITUNG ABSEN & TELAT
        $stAbs = $mysqli->prepare("SELECT jam_masuk, jam_keluar FROM absen WHERE user_id=? AND DATE(tanggal) >= ? AND DATE(tanggal) <= ? AND jam_masuk IS NOT NULL");
        $stAbs->bind_param("iss", $user_id, $start, $calc_end);
        $stAbs->execute();
        $resAbs = $stAbs->get_result();
        while ($r = $resAbs->fetch_assoc()) {
            if (empty($r['jam_masuk'])) continue;
            if (date('H:i:s', strtotime($r['jam_masuk'])) > OFFICE_START_PREV) $telat_count++;
            
            if (!empty($r['jam_keluar']) && $r['jam_keluar'] != '00:00:00') {
                $in = new DateTime($r['jam_masuk']); $out = new DateTime($r['jam_keluar']);
                $dur = ($in->diff($out)->h) + ($in->diff($out)->i / 60);
                if ($dur >= 7) $hadir_poin += 1.0; 
                elseif ($dur >= 3.5) $hadir_poin += 0.5; 
                else $hadir_poin += 0.5; 
            } else { $hadir_poin += 1.0; }
        }
        $potongan_telat_rp = $telat_count * PENALTY_TELAT;

        // 3. HITUNG LEMBUR
        $stLembur = $mysqli->prepare("SELECT COALESCE(SUM(total_menit), 0) as tot_menit, COALESCE(SUM(total_upah), 0) as uang_lembur FROM lembur WHERE user_id = ? AND DATE(tanggal) >= ? AND DATE(tanggal) <= ?");
        $stLembur->bind_param("iss", $user_id, $start, $calc_end);
        $stLembur->execute();
        $resLembur = $stLembur->get_result()->fetch_assoc();
        $total_menit_lembur = (int)($resLembur['tot_menit'] ?? 0);
        $total_lembur_rp    = (int)($resLembur['uang_lembur'] ?? 0);
    }

    // 4. BONUS
    $current_month = date('Y-m', strtotime($start));
    $check_stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM gaji_run WHERE user_id = ? AND periode_start LIKE ?");
    $param_like = $current_month . '%';
    $check_stmt->bind_param("is", $user_id, $param_like);
    $check_stmt->execute();
    if (($check_stmt->get_result()->fetch_assoc()['count'] ?? 0) == 0) {
        $bln_lalu_start = date('Y-m-01', strtotime($start . ' -1 month'));
        $bln_lalu_end   = date('Y-m-t', strtotime($start . ' -1 month'));
        $stLalu = $mysqli->prepare("SELECT COUNT(DISTINCT tanggal) as tot FROM absen WHERE user_id=? AND DATE(tanggal) BETWEEN ? AND ? AND jam_masuk IS NOT NULL");
        $stLalu->bind_param("iss", $user_id, $bln_lalu_start, $bln_lalu_end);
        $stLalu->execute();
        $hadir_lalu = (int)($stLalu->get_result()->fetch_assoc()['tot'] ?? 0);
        
        $hari_kerja_lalu = 0;
        $iter = new DateTime($bln_lalu_start); $last = new DateTime($bln_lalu_end);
        while ($iter <= $last) { if ($iter->format('N') != 7) $hari_kerja_lalu++; $iter->modify('+1 day'); }
        
        $bolos = max(0, $hari_kerja_lalu - $hadir_lalu);
        if ($bolos == 0) $bonus = 200000; elseif ($bolos == 1) $bonus = 100000;
    }

    // =========================================================
    // 5. 🔥 ANGSURAN (LOGIC NARIK DATA POTONGAN) 🔥
    // =========================================================
    $angsuran_sisa = 0;
    
    // Ambil data hutang aktif
    $qAng = $mysqli->query("SELECT * FROM angsuran WHERE user_id=$user_id AND status='disetujui' AND sisa > 0");

    if ($qAng) {
        while ($rowA = $qAng->fetch_assoc()) {
            $angsuran_id = $rowA['id'];
            $sisa_hutang = (int)$rowA['sisa'];
            $cicilan = 0;

            // A. Cek Kolom di Tabel Utama (Kali aja Abang udah nambahin)
            if (isset($rowA['nominal_angsuran']) && $rowA['nominal_angsuran'] > 0) {
                $cicilan = (int)$rowA['nominal_angsuran'];
            } 
            elseif (isset($rowA['potongan']) && $rowA['potongan'] > 0) {
                $cicilan = (int)$rowA['potongan'];
            }

            // B. Kalau di Tabel Utama gak ada (0), Cek Riwayat Terakhir!
            // Kita "tarik" dari tabel angsuran_potongan seperti request Abang.
            if ($cicilan == 0) {
                $qHistory = $mysqli->query("SELECT potongan FROM angsuran_potongan WHERE angsuran_id=$angsuran_id ORDER BY id DESC LIMIT 1");
                if ($qHistory && $rowHist = $qHistory->fetch_assoc()) {
                    $cicilan = (int)$rowHist['potongan'];
                }
            }

            // C. Kalau masih 0 juga (Belum pernah bayar sama sekali), pakai DEFAULT
            if ($cicilan == 0) {
                $cicilan = DEFAULT_ANGSURAN; // Rp 300.000
            }

            // D. Validasi: Jangan bayar lebih dari sisa hutang
            if ($cicilan > $sisa_hutang) {
                $cicilan = $sisa_hutang;
            }

            $angsuran_sisa += $cicilan;
        }
    }

    // 6. TOTAL FINAL
    $gaji_pokok_final = ($role === 'admin' || $role === 'owner') ? 
        (((new DateTime($start))->diff(new DateTime($end))->days + 1 <= 7) ? (int)($nominal_gaji/4) : $nominal_gaji) : 
        (int)($hadir_poin * $nominal_gaji);

    $total_kotor = $gaji_pokok_final + $total_lembur_rp + $bonus;
    $gaji_bersih = max(0, $total_kotor - ($potongan_telat_rp + $angsuran_sisa));

    echo json_encode([
        "success" => true,
        "data" => [
            "user_id" => $user_id,
            "nama" => $nama_karyawan,
            "role" => $role,
            "periode_start" => $start,
            "periode_end" => $end,
            "hadir_minggu" => (float)$hadir_poin,
            "nominal_dasar" => $nominal_gaji,
            "total_gaji_pokok" => $gaji_pokok_final,
            "lembur_rp" => $total_lembur_rp, 
            "bonus_bulanan" => $bonus,
            "total_telat" => (int)$telat_count,       
            "potongan_telat_rp" => $potongan_telat_rp, 
            
            // Angka ini sekarang "PINTAR" (Narik database dulu, kalau gak ada baru default)
            "angsuran_rp" => $angsuran_sisa, 
            
            "total_diterima" => $gaji_bersih
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
?>