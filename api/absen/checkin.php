<?php
// api/absen/checkin.php

// 1. SET TIMEZONE
date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';

// =========================================================
// ⚙️ KONFIGURASI JAM
// =========================================================
const BATAS_LEMBUR_PAGI = '07:30:00'; 
const JAM_TARGET_MASUK  = '08:00:00'; 
const START_TIME_REWARD = '07:40:00'; 

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    $user_id = (int)($_POST['user_id'] ?? 0);
    $lat     = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
    $lng     = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
    $alasan  = isset($_POST['alasan']) ? trim((string)$_POST['alasan']) : '';
    $tipe_absen = isset($_POST['tipe_absen']) ? strtoupper(trim($_POST['tipe_absen'])) : 'BIASA';

    if ($user_id <= 0) throw new Exception('User ID tidak valid');

    // Handle Foto
    $fotoPath = null;
    if (!empty($_FILES['foto']['tmp_name'])) {
        $dir = __DIR__ . '/../../uploads/absen';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $prefix = ($tipe_absen === 'LEMBUR') ? 'lembur_' : 'masuk_';
        $fname = $prefix . $user_id . '_' . date('Ymd_His') . '.jpg';
        if (move_uploaded_file($_FILES['foto']['tmp_name'], $dir . '/' . $fname)) {
            $fotoPath = 'uploads/absen/' . $fname;
        }
    }

    // ============================================================
    // A. INPUT LEMBUR MANUAL
    // ============================================================
    if ($tipe_absen === 'LEMBUR') {
        $q = $conn->prepare("INSERT INTO lembur (user_id, tanggal, jam_masuk, alasan, foto_bukti, jenis_lembur, status, total_menit, total_upah) VALUES (?, CURDATE(), NOW(), ?, ?, 'over', 'pending', 0, 0)");
        $q->bind_param("iss", $user_id, $alasan, $fotoPath);
        if ($q->execute()) {
            echo json_encode(['success'=>true, 'message'=>'Lembur Over Berhasil!']);
        } else {
            throw new Exception("Gagal simpan lembur: " . $q->error);
        }
        exit;
    }

    // ============================================================
    // B. ABSEN HARIAN BIASA (JURUS ANTI-DOUBLE TANPA UNIQUE)
    // ============================================================
    
    // 1. COBA INSERT HANYA JIKA BELUM ADA (WHERE NOT EXISTS)
    // Teknik ini mencegah insert ganda walaupun tombol ditekan cepat
    $ins = $conn->prepare("
        INSERT INTO absen (user_id, tanggal, jam_masuk, masuk_lat, masuk_lng, foto_masuk, alasan, status) 
        SELECT ?, CURDATE(), CURTIME(), ?, ?, ?, NULLIF(?, ''), 'HADIR'
        FROM DUAL
        WHERE NOT EXISTS (
            SELECT id FROM absen WHERE user_id = ? AND tanggal = CURDATE()
        )
    ");
    // Parameter: user_id, lat, lng, foto, alasan, user_id (buat cek)
    $ins->bind_param("iddssi", $user_id, $lat, $lng, $fotoPath, $alasan, $user_id);
    $ins->execute();

    // 2. PASTI UPDATE (Hanya update data yang kosong/perlu direfresh)
    // Kalau insert di atas berhasil (data baru) -> ini tidak ngefek banyak.
    // Kalau insert di atas gagal (karena sudah ada) -> ini akan mengupdate data yang ada.
    $upd = $conn->prepare("
        UPDATE absen 
        SET jam_masuk  = IFNULL(jam_masuk, CURTIME()),
            masuk_lat  = ?, 
            masuk_lng  = ?, 
            foto_masuk = IFNULL(foto_masuk, ?),
            alasan     = IF(alasan IS NULL OR alasan = '', ?, alasan),
            status     = 'HADIR'
        WHERE user_id = ? AND tanggal = CURDATE()
    ");
    $upd->bind_param("ddssi", $lat, $lng, $fotoPath, $alasan, $user_id);
    $upd->execute();

    // 2. Cek Reward Minggu
    $hari_ini = (int)date('w'); 
    $jam_skrg = date('H:i:s'); 

    if ($hari_ini === 0 && $jam_skrg <= START_TIME_REWARD) {
        $cek = $conn->query("SELECT id FROM event_points_history WHERE user_id=$user_id AND DATE(created_at)=CURDATE() AND note LIKE '%Minggu%'");
        if ($cek->num_rows == 0) {
            $conn->query("INSERT INTO event_points_history (user_id, change_coins, type, note, created_at) VALUES ($user_id, 10000, 'earn', 'Reward Masuk Minggu', NOW())");
        }
    }

    // ============================================================
    // 🔥 3. AUTO LEMBUR PAGI (FILTER KETAT) 🔥
    // ============================================================
    $status_lembur = "Normal (Tidak Masuk Lembur)"; 

    if ($jam_skrg < BATAS_LEMBUR_PAGI) {
        
        // Cek lagi biar gak dobel insert di tabel LEMBUR
        $cekLemburPagi = $conn->query("SELECT id FROM lembur WHERE user_id = $user_id AND tanggal = CURDATE() AND jenis_lembur = 'biasa'");
        
        if ($cekLemburPagi->num_rows == 0) {
            // Hitung selisih
            $waktuDatang = strtotime($jam_skrg);
            $waktuMasuk  = strtotime(JAM_TARGET_MASUK);
            $selisih     = $waktuMasuk - $waktuDatang; 

            if ($selisih > 0) {
                $menitLembur = (int)floor($selisih / 60);
                
                if ($menitLembur > 0) {
                    // Hitung Uang
                    $uData = $conn->query("SELECT lembur FROM users WHERE id = $user_id")->fetch_assoc();
                    $tarif = (float)($uData['lembur'] ?? 20000);
                    $upah  = (int)ceil(($menitLembur / 60) * $tarif);
                    $jamDecimal = round($menitLembur / 60, 2);

                    $tglNow = date('Y-m-d');
                    $jamMsk = $tglNow . ' ' . $jam_skrg;
                    $jamKlr = $tglNow . ' ' . JAM_TARGET_MASUK;
                    $ket    = "Lembur Pagi (Auto)";
                    $nol    = 0; 

                    $sqlLembur = "INSERT INTO lembur (
                        user_id, tanggal, jam_masuk, jam_keluar, 
                        total_menit, total_menit_masuk, total_menit_keluar, 
                        total_jam, total_upah, 
                        jenis_lembur, status, alasan, foto_bukti
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'biasa', 'approved', ?, ?)";

                    $stmt = $conn->prepare($sqlLembur);
                    $stmt->bind_param("isssiiidiss", 
                        $user_id, $tglNow, $jamMsk, $jamKlr, 
                        $menitLembur, $menitLembur, $nol,
                        $jamDecimal, $upah, 
                        $ket, $fotoPath
                    );

                    if ($stmt->execute()) {
                        $status_lembur = "SUKSES INSERT LEMBUR ($menitLembur mnt)";
                    }
                }
            }
        }
    }

    // Response Akhir
    $row = $conn->query("SELECT * FROM absen WHERE user_id=$user_id AND tanggal=CURDATE()")->fetch_assoc();

    echo json_encode([
        'success' => true, 
        'message' => 'Absen Berhasil!', 
        'data'    => $row,
        'debug_lembur_status' => $status_lembur
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'Error: '.$e->getMessage()]);
}
?>