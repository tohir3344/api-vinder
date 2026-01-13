<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    http_response_code(204); 
    exit; 
}

require_once __DIR__ . '/../Koneksi.php'; // $conn dari sini

// ===== KONFIGURASI LEMBUR SORE (HARD RULE) =====
const JAM_NORMAL_KELUAR = '17:00:00';
const BATAS_LEMBUR_SORE = '17:30:00';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success'=>false,'message'=>'Method not allowed']); 
        exit;
    }

    $user_id = (int)($_POST['user_id'] ?? 0);

    // Dukung dua nama field: lat/lng ATAU keluar_lat/keluar_lng
    $lat = isset($_POST['keluar_lat']) ? (float)$_POST['keluar_lat']
         : (isset($_POST['lat']) ? (float)$_POST['lat'] : null);
    $lng = isset($_POST['keluar_lng']) ? (float)$_POST['keluar_lng']
         : (isset($_POST['lng']) ? (float)$_POST['lng'] : null);

    // Alasan khusus keluar (optional, TIDAK disimpan di tabel absen tapi di tabel lembur)
    $alasan_keluar = isset($_POST['alasan_keluar']) ? trim((string)$_POST['alasan_keluar']) : '';

    if ($user_id <= 0 || $lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'user_id, lat, lng wajib']); 
        exit;
    }

    // --- PROSES UPLOAD FOTO ---
    $fotoPath = null;
    $MAX_BYTES = 500 * 1024; // 500KB
    if (!empty($_FILES['foto']['tmp_name'])) {
        if (($_FILES['foto']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success'=>false,'message'=>'Upload error']); exit;
        }
        if (($_FILES['foto']['size'] ?? 0) > $MAX_BYTES) {
            http_response_code(400);
            echo json_encode(['success'=>false,'message'=>'File terlalu besar (maks 500KB).']); exit;
        }

        $dir = __DIR__ . '/../uploads/absen';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        $fname = 'keluar_' . $user_id . '_' . date('Ymd_His') . '.jpg';
        $dest  = $dir . '/' . $fname;
        if (move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
            $fotoPath = 'uploads/absen/' . $fname;
        }
    }

    // --- 1. PROSES UPDATE DATA ABSEN (Logika Anti-Duplikat) ---
    // Kita coba Update dulu baris yang sudah ada (Check-in tadi pagi)
    $upd = $conn->prepare("
        UPDATE absen
           SET jam_keluar   = IFNULL(jam_keluar, CURTIME()),
               keluar_lat   = IFNULL(keluar_lat,  ?),
               keluar_lng   = IFNULL(keluar_lng,  ?),
               foto_keluar  = IFNULL(foto_keluar, ?)
         WHERE user_id=? AND tanggal=CURDATE()
         LIMIT 1
    ");
    $upd->bind_param("ddsi", $lat, $lng, $fotoPath, $user_id);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    // --- 2. LOGIKA FALLBACK ---
    // Jika user lupa check-in (tidak ada record hari ini), baru kita buat baris baru
    if ($affected === 0) {
        $ins = $conn->prepare("
            INSERT INTO absen (user_id, tanggal, jam_keluar, keluar_lat, keluar_lng, foto_keluar)
            VALUES (?, CURDATE(), CURTIME(), ?, ?, ?)
        ");
        $ins->bind_param("idds", $user_id, $lat, $lng, $fotoPath);
        $ins->execute();
        $ins->close();
    }

    // Ambil data terbaru dari database untuk response ke aplikasi
    $sel = $conn->prepare("SELECT * FROM absen WHERE user_id=? AND tanggal=CURDATE() LIMIT 1");
    $sel->bind_param("i", $user_id);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();

    $tanggal    = $row['tanggal']    ?? date('Y-m-d');
    $jam_masuk  = $row['jam_masuk']  ?? null;
    $jam_keluar = $row['jam_keluar'] ?? date('H:i:s');

    // --- BANGUN URL FOTO DINAMIS ---
    $foto_url = null;
    if (!empty($row['foto_keluar'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/'); 
        $appRoot   = dirname(dirname($scriptDir)); 
        $publicBase = rtrim("$scheme://$host$appRoot", '/');
        $foto_url = $publicBase . '/' . ltrim($row['foto_keluar'], '/');
    }

    // ============================================================
    // 🔥 HITUNG OTOMATIS LEMBUR KELUAR (HARD RULE) 🔥
    // ============================================================
    $menitLemburKeluar = 0;
    try {
        if ($jam_keluar > BATAS_LEMBUR_SORE) {
            $waktuKeluar = strtotime($jam_keluar);
            $waktuNormal = strtotime(JAM_NORMAL_KELUAR); // 17:00:00
            $selisihDetik = $waktuKeluar - $waktuNormal;
            if ($selisihDetik > 0) {
                $menitLemburKeluar = (int)floor($selisihDetik / 60);
            }
        } 

        if ($menitLemburKeluar > 0 || !empty($alasan_keluar)) {
            $stmtLembur = $conn->prepare("
                INSERT INTO lembur (user_id, tanggal, jam_keluar, total_menit_keluar, alasan_keluar)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    jam_keluar = VALUES(jam_keluar),
                    total_menit_keluar = VALUES(total_menit_keluar),
                    alasan_keluar = IF(VALUES(alasan_keluar) != '', VALUES(alasan_keluar), alasan_keluar)
            ");
            $stmtLembur->bind_param("issis", $user_id, $tanggal, $jam_keluar, $menitLemburKeluar, $alasan_keluar);
            $stmtLembur->execute();
            $stmtLembur->close();
        }
    } catch (\Throwable $e) {
        // Silent error lembur
    }

    echo json_encode([
        'success'     => true,
        'message'     => 'Check-out OK',
        'tanggal'     => $tanggal,
        'jam_masuk'   => $jam_masuk,
        'jam_keluar'  => $jam_keluar,
        'data'        => $row,
        'foto_url'    => $foto_url,
        'debug_lembur'=> $menitLemburKeluar . ' menit'
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
?>