<?php
// api/lembur/rekap_generate.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Pastikan path koneksi benar
require_once __DIR__ . '/../Koneksi.php'; 

// Error Reporting untuk debugging (bisa dimatikan saat production)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', '0'); // Return JSON only

// --- Helper Functions ---
function j_ok($data){ echo json_encode(['success'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
function j_bad($msg,$http=200){ http_response_code($http); echo json_encode(['success'=>false,'message'=>$msg], JSON_UNESCAPED_UNICODE); exit; }
function toYmd($d){ return date('Y-m-d', $d); }

// --- Validasi Koneksi ---
if (!isset($conn) || !($conn instanceof mysqli)) j_bad('Koneksi database tidak tersedia', 500);
$conn->set_charset('utf8mb4');

// --- Ambil Input Parameter ---
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: 'null', true);
$q = array_merge($_GET ?? [], is_array($body) ? $body : []);

$type   = strtoupper(trim((string)($q['type'] ?? 'WEEKLY')));
$userId = isset($q['user_id']) ? (int)$q['user_id'] : null;

// --- Tentukan Periode Waktu (Mingguan/Bulanan) ---
$today = time();

if ($type === 'MONTHLY' || $type === 'MONTH') {
    $type = 'MONTH';
    $monthStr = trim((string)($q['month'] ?? '')); 
    if ($monthStr && preg_match('/^\d{4}-\d{2}$/', $monthStr)) {
        $start = strtotime($monthStr . '-01 00:00:00');
    } else {
        $start = strtotime(date('Y-m-01 00:00:00', $today));
    }
    $end = strtotime(date('Y-m-t 23:59:59', $start));
    $periodeType = 'MONTH';
} else {
    // WEEK (Default)
    $type = 'WEEK';
    $startParam = trim((string)($q['start'] ?? ''));
    $endParam   = trim((string)($q['end'] ?? ''));
    if ($startParam && $endParam) {
        $start = strtotime($startParam . ' 00:00:00');
        $end   = strtotime($endParam   . ' 23:59:59');
    } else {
        $dow = (int)date('N', $today); 
        $monday = strtotime('-' . ($dow - 1) . ' days', $today);
        $start  = strtotime(date('Y-m-d 00:00:00', $monday));
        $end    = strtotime(date('Y-m-d 23:59:59', strtotime('+6 days', $start)));
    }
    $periodeType = 'WEEK';
}

$startStr = toYmd($start);
$endStr   = toYmd($end);

// --- Query Data Lembur ---
$where = 'l.tanggal BETWEEN ? AND ?';
$params = [$startStr, $endStr];
$types  = 'ss';

if ($userId) { 
    $where .= ' AND l.user_id = ?'; 
    $params[] = $userId; 
    $types .= 'i'; 
}

// Ambil data lembur join user untuk dapat rate/nominal
$sql = "
    SELECT 
        l.user_id, l.tanggal, l.jam_masuk, l.jam_keluar, l.jenis_lembur, 
        l.total_upah, 
        COALESCE(u.nama_lengkap, u.username, CONCAT('User#', l.user_id)) AS nama,
        COALESCE(u.lembur, 0) AS rate_user_jam
    FROM lembur l
    LEFT JOIN users u ON u.id = l.user_id
    WHERE $where
    ORDER BY l.user_id, l.tanggal ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$userStats = [];

// --- Proses Looping Data ---
while ($row = $res->fetch_assoc()) {
    $uid  = (int)$row['user_id'];
    $rate = (float)$row['rate_user_jam']; // Ini bisa Nominal Fix (Over) atau Rate Per Jam (Biasa)
    
    // Inisialisasi Array User jika belum ada
    if (!isset($userStats[$uid])) {
        $userStats[$uid] = [
            'user_id' => $uid,
            'nama' => $row['nama'],
            'rate_per_jam' => $rate,
            'total_menit' => 0,
            'subtotal_upah' => 0
        ];
    }

    // ============================================================
    // PRIORITY 1: DATA DATABASE (JIKA SUDAH DISIMPAN UPSERT)
    // ============================================================
    $upahDiDB = (float)$row['total_upah'];

    if ($upahDiDB > 0) {
        // Jika DB sudah ada angka, pakai angka itu (Paling Akurat)
        $userStats[$uid]['subtotal_upah'] += $upahDiDB;

        // Hitung durasi menit hanya untuk statistik/tampilan
        if (!empty($row['jam_masuk']) && !empty($row['jam_keluar'])) {
            try {
                $s = new DateTime($row['tanggal'].' '.$row['jam_masuk']);
                $e = new DateTime($row['tanggal'].' '.$row['jam_keluar']);
                if ($e < $s) $e->modify('+1 day');
                
                // Logic start jam 17:00 untuk statistik menit (opsional)
                $batasStart = new DateTime($row['tanggal'].' 17:00:00');
                if ($s < $batasStart) $s = clone $batasStart;
                
                if ($e > $s) {
                    $diff = ($e->getTimestamp() - $s->getTimestamp()) / 60;
                    $userStats[$uid]['total_menit'] += $diff;
                }
            } catch(Exception $x){}
        }
        continue; // Skip hitungan manual, lanjut record berikutnya
    }

    // ============================================================
    // PRIORITY 2: HITUNG MANUAL (JIKA DB MASIH 0/NULL)
    // ============================================================

    if (empty($row['jam_masuk']) || empty($row['jam_keluar'])) continue;

    try {
        $startL = new DateTime($row['tanggal'] . ' ' . $row['jam_masuk']);
        $endL   = new DateTime($row['tanggal'] . ' ' . $row['jam_keluar']);
    } catch (Exception $e) { continue; }

    if ($endL < $startL) $endL->modify('+1 day');

    $upahBaris = 0;
    $jenisLembur = $row['jenis_lembur'] ?? 'biasa';

    // >>> LOGIC UTAMA REVISI ANDA <<<
    
    if ($jenisLembur === 'over') {
        // KASUS 1: JENIS OVER
        // Logic: Langsung ambil angka dari DB User, tanpa dikali jam
        $upahBaris = $rate; 
        
        // Menit tetap dihitung buat laporan
        $menit = ($endL->getTimestamp() - $startL->getTimestamp()) / 60;
        $userStats[$uid]['total_menit'] += $menit;
    } 
    else {
        // KASUS 2: JENIS BIASA
        // Logic: Start 17:00. 17-20 (x1), 20++ (x2)
        
        $batasBawah  = new DateTime($row['tanggal'] . ' 17:00:00'); 
        $batasDouble = new DateTime($row['tanggal'] . ' 20:00:00');

        // Geser waktu mulai ke 17:00 jika user datang sebelum jam 5 sore
        $startHitung = clone $startL;
        if ($startHitung < $batasBawah) $startHitung = clone $batasBawah; 

        // Validasi: Kalau jam pulang < jam mulai hitung (misal pulang jam 16:00), skip
        if ($endL <= $startHitung) {
            $upahBaris = 0; 
        } else {
            // Tambahkan ke statistik menit
            $menit = ($endL->getTimestamp() - $startHitung->getTimestamp()) / 60;
            $userStats[$uid]['total_menit'] += $menit;

            // Hitung Rupiah
            if ($endL <= $batasDouble) {
                // Skenario A: Pulang sebelum/pas jam 20:00 (Full x1)
                $jam = ($endL->getTimestamp() - $startHitung->getTimestamp()) / 3600;
                $upahBaris += ($jam * $rate * 1);
            }
            elseif ($startHitung >= $batasDouble) {
                // Skenario B: Datang setelah jam 20:00 (Full x2)
                $jam = ($endL->getTimestamp() - $startHitung->getTimestamp()) / 3600;
                $upahBaris += ($jam * $rate * 2);
            }
            else {
                // Skenario C: Nyebrang (Split Time)
                $jamNormal = ($batasDouble->getTimestamp() - $startHitung->getTimestamp()) / 3600;
                $jamDouble = ($endL->getTimestamp() - $batasDouble->getTimestamp()) / 3600;
                
                $upahBaris += ($jamNormal * $rate * 1); // x1
                $upahBaris += ($jamDouble * $rate * 2); // x2
            }
        }
    }

    $userStats[$uid]['subtotal_upah'] += $upahBaris;
}
$stmt->close();

// --- Finalisasi Array & Update ke Tabel Subtotal ---
$rows = [];
foreach ($userStats as $uid => $stat) {
    // Pembulatan ke atas biar rapi
    $stat['subtotal_upah'] = ceil($stat['subtotal_upah']);
    $rows[] = $stat;
}

// Prepare Query untuk Subtotal
$sel = $conn->prepare("SELECT id FROM subtotal_upah_user WHERE user_id=? AND periode_type=? AND periode_start=? AND periode_end=? LIMIT 1");
$ins = $conn->prepare("INSERT INTO subtotal_upah_user (user_id, periode_type, periode_start, periode_end, subtotal_upah, created_at, updated_at) VALUES (?,?,?,?,?, NOW(), NOW())");
$upd = $conn->prepare("UPDATE subtotal_upah_user SET subtotal_upah=?, updated_at=NOW() WHERE id=?");

$affected = 0;
foreach ($rows as $r) {
    // Cek apakah data subtotal minggu/bulan ini sudah ada?
    $sel->bind_param('isss', $r['user_id'], $periodeType, $startStr, $endStr);
    $sel->execute();
    $selRes = $sel->get_result();
    
    if ($selRes && ($cur = $selRes->fetch_assoc())) {
        // UPDATE
        $id = (int)$cur['id'];
        $upd->bind_param('di', $r['subtotal_upah'], $id);
        $upd->execute();
        $affected += $upd->affected_rows;
    } else {
        // INSERT BARU
        $ins->bind_param('isssd', $r['user_id'], $periodeType, $startStr, $endStr, $r['subtotal_upah']);
        $ins->execute();
        $affected += $ins->affected_rows;
    }
}

// --- Output JSON ---
j_ok([
    'periode' => [
        'type' => $periodeType,
        'start' => $startStr,
        'end' => $endStr
    ],
    'info' => "Generate Sukses. Logic: Over=Flat, Biasa=Time(x1/x2)",
    'rows' => $rows
]);
?>