<?php
// api/lembur/fix_data.php
// FILE INI HANYA DIJALANKAN SEKALI UNTUK MEMPERBAIKI DATA LAMA
declare(strict_types=1);
header("Content-Type: text/plain");

// Pastikan path koneksi benar
require_once __DIR__ . '/../Koneksi.php';

// Cek koneksi
if (!isset($conn) && isset($db)) $conn = $db;
if (!$conn) die("Koneksi Database Gagal.");

echo "=== MULAI PROSES PERBAIKAN DATA LEMBUR (LOGIC 17-20 x1, >20 x2) ===\n\n";

// 1. AMBIL SEMUA DATA LEMBUR + TARIF USERNYA
$sql = "SELECT l.id, l.user_id, l.tanggal, l.jam_masuk, l.jam_keluar, u.lembur as tarif 
        FROM lembur l 
        LEFT JOIN users u ON u.id = l.user_id 
        WHERE l.jam_masuk IS NOT NULL AND l.jam_keluar IS NOT NULL";

$res = $conn->query($sql);

if (!$res) die("Error Query: " . $conn->error);

$count = 0;
$total_updated = 0;

while ($row = $res->fetch_assoc()) {
    $id = $row['id'];
    $tarif = (int)($row['tarif'] ?? 0);
    
    // Kalau user gak punya tarif lembur, kita skip atau set default?
    // Kita set default 10.000 biar gak 0 rupiah.
    if ($tarif == 0) $tarif = 10000; 
    
    // --- LOGIKA HITUNG ULANG ---
    $tgl = $row['tanggal'];
    // Bersihkan format jam (ganti titik jadi titik dua)
    $jm = str_replace('.', ':', $row['jam_masuk']);
    $jk = str_replace('.', ':', $row['jam_keluar']);

    try {
        $startL = new DateTime("$tgl $jm");
        $endL   = new DateTime("$tgl $jk");
        
        // Handle lewat tengah malam
        if ($endL < $startL) $endL->modify('+1 day');

        // Batas Waktu
        $batasBawah  = new DateTime("$tgl 17:00:00");
        $batasDouble = new DateTime("$tgl 20:00:00");

        // Normalisasi Start (Mulai hitung 17:00)
        if ($startL < $batasBawah) $startL = clone $batasBawah; 
        
        $upahBaru = 0;

        // Validasi: Kalau End <= Start, berarti pulang sebelum jam 17:00 -> Rp 0
        if ($endL > $startL) {
            $menit = ($endL->getTimestamp() - $startL->getTimestamp()) / 60;
            
            if ($menit > 0) {
                $rp = 0;
                if ($endL <= $batasDouble) {
                    // Full Normal (17:00 - 20:00)
                    $rp = ($menit/60) * $tarif;
                } elseif ($startL >= $batasDouble) {
                    // Full Double (> 20:00)
                    $rp = ($menit/60) * $tarif * 2;
                } else {
                    // Split (Nyebrang jam 20:00)
                    $secsNorm = $batasDouble->getTimestamp() - $startL->getTimestamp();
                    $secsDoub = $endL->getTimestamp() - $batasDouble->getTimestamp();
                    
                    $rp = (max(0,$secsNorm)/3600 * $tarif) + (max(0,$secsDoub)/3600 * $tarif * 2);
                }
                $upahBaru = (int)ceil($rp);
            }
        }

        // UPDATE DATABASE
        // Kita update 'total_upah' saja. 'total_menit' opsional.
        $updSql = "UPDATE lembur SET total_upah = $upahBaru WHERE id = $id";
        if ($conn->query($updSql)) {
            echo "ID: $id | User: {$row['user_id']} | Jam: $jm-$jk | Tarif: $tarif | Hasil: Rp " . number_format($upahBaru) . " ... [OK]\n";
            $total_updated++;
        } else {
            echo "ID: $id ... [GAGAL UPDATE] " . $conn->error . "\n";
        }
        
        $count++;

    } catch (Exception $e) {
        echo "ID: $id ... [ERROR] " . $e->getMessage() . "\n";
    }
}

echo "\n=============================================";
echo "\nSELESAI! Total Data: $count | Berhasil Diupdate: $total_updated";
echo "\nSekarang cek halaman Lembur & Gaji, harusnya sudah sama.";
echo "\n=============================================";
?>