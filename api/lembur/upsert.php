<?php
// api/lembur/upsert.php
declare(strict_types=1);

// Matikan error display agar response JSON bersih dari warning PHP
ini_set('display_errors', '0');
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    http_response_code(204); 
    exit; 
}

require_once __DIR__ . '/../Koneksi.php';

/* ===== Helpers ===== */
function normHHMMSS($v){
    if(!$v) return null;
    $t=str_replace('.',':',trim((string)$v));
    if(!preg_match('/^\d{1,2}:\d{1,2}(:\d{1,2})?$/',$t)) return null;
    $p=explode(':',$t);
    return sprintf('%02d:%02d:%02d',(int)($p[0]??0),(int)($p[1]??0),(int)($p[2]??0));
}

function isValidDateYmd($d){
    return preg_match('/^\d{4}-\d{2}-\d{2}$/',$d);
}

try {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
  
    // 1. Validasi Input Dasar
    $user_id = (int)($j['user_id'] ?? 0);
    $tanggal = trim((string)($j['tanggal'] ?? ''));

    if ($user_id <= 0) throw new Exception("User ID Kosong");
    if (!isValidDateYmd($tanggal)) throw new Exception("Format Tanggal Salah");

    $alasan         = $j['alasan'] ?? '';
    $alasan_keluar  = $j['alasan_keluar'] ?? '';
    $jam_masuk      = normHHMMSS($j['jam_masuk'] ?? null);
    $jam_keluar     = normHHMMSS($j['jam_keluar'] ?? null);

    // 2. Fallback ke Data Absen jika input jam kosong (Otomatis ambil dari mesin absen)
    if (!$jam_masuk || !$jam_keluar) {
        $q = $conn->prepare("SELECT jam_masuk, jam_keluar FROM absen WHERE user_id=? AND tanggal=? LIMIT 1");
        $q->bind_param('is', $user_id, $tanggal);
        $q->execute();
        $abs = $q->get_result()->fetch_assoc();
        if (!$jam_masuk) $jam_masuk = normHHMMSS($abs['jam_masuk'] ?? null);
        if (!$jam_keluar) $jam_keluar = normHHMMSS($abs['jam_keluar'] ?? null);
    }

    if (!$jam_masuk || !$jam_keluar) throw new Exception("Jam Masuk/Keluar tidak ditemukan. Pastikan sudah absen.");

    // =============================================================
    // 3. [PENTING] AMBIL TARIF USER DARI DATABASE
    // =============================================================
    $qUser = $conn->prepare("SELECT lembur FROM users WHERE id=? LIMIT 1");
    $qUser->bind_param('i', $user_id);
    $qUser->execute();
    $dUser = $qUser->get_result()->fetch_assoc();
    
    // Ambil rate per jam. Default 0 jika tidak diset.
    $rate_per_jam = (float)($dUser['lembur'] ?? 0);

    // =============================================================
    // 4. HITUNG DURASI LEMBUR (START 17:00)
    // =============================================================
    
    $dtMasuk  = new DateTime("$tanggal $jam_masuk");
    $dtKeluar = new DateTime("$tanggal $jam_keluar");
    
    // Jika keluar lebih kecil dari masuk (Lembur sampai dini hari besoknya)
    if ($dtKeluar < $dtMasuk) $dtKeluar->modify('+1 day');

    // ATURAN: Lembur dimulai jam 17:00
    $batasMulai = new DateTime("$tanggal 17:00:00");
    
    // Jika karyawan masuk/absen sebelum 17:00, hitungan durasi tetap start dari 17:00
    if ($dtMasuk < $batasMulai) $dtMasuk = clone $batasMulai;

    $total_menit = 0;
    
    // Hitung selisih waktu
    if ($dtKeluar > $dtMasuk) {
        $diff = $dtKeluar->getTimestamp() - $dtMasuk->getTimestamp();
        $total_menit = floor($diff / 60); // Jadikan menit
    }

    // =============================================================
    // 5. HITUNG DUIT (JAM * TARIF USER)
    // =============================================================
    
    $total_jam_desimal = $total_menit / 60; // Contoh: 90 menit = 1.5 jam
    
    // Rumus Utama:
    $total_upah_float = $total_jam_desimal * $rate_per_jam;
    
    // Pembulatan ke atas (biar karyawan senang, gak ada koma)
    $total_upah = (int)ceil($total_upah_float); 
    $total_jam  = round($total_jam_desimal, 2);

    // =============================================================
    // 6. SIMPAN KE DATABASE
    // =============================================================
    $sql = "INSERT INTO lembur 
            (user_id, tanggal, jam_masuk, jam_keluar, alasan, alasan_keluar, total_menit, total_upah, total_jam)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            jam_masuk=VALUES(jam_masuk), 
            jam_keluar=VALUES(jam_keluar), 
            alasan=VALUES(alasan), 
            alasan_keluar=VALUES(alasan_keluar),
            total_menit=VALUES(total_menit), 
            total_upah=VALUES(total_upah), 
            total_jam=VALUES(total_jam)";
  
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isssssiid', $user_id, $tanggal, $jam_masuk, $jam_keluar, $alasan, $alasan_keluar, $total_menit, $total_upah, $total_jam);
  
    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "message" => "Data tersimpan",
            "debug"   => [
                "rate_user_yg_dipakai" => $rate_per_jam,
                "total_jam" => $total_jam,
                "total_upah" => $total_upah
            ]
        ]);
    } else {
        throw new Exception("DB Error: " . $stmt->error);
    }

} catch (Exception $e) {
    http_response_code(400); // Bad Request
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>