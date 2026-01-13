<?php
// api/fix_harga_lembur.php
require_once __DIR__ . '/../Koneksi.php';

header("Content-Type: text/plain");

echo "=== MULAI PERBAIKAN HARGA LEMBUR ===\n\n";

// 1. Ambil semua data user dan tarif lemburnya saat ini
$users = [];
$qUser = $conn->query("SELECT id, nama_lengkap, lembur FROM users");
while($u = $qUser->fetch_assoc()) {
    $users[$u['id']] = [
        'nama' => $u['nama_lengkap'],
        'rate' => (float)$u['lembur'] // Tarif lembur user saat ini (misal: 50000)
    ];
}

// 2. Ambil semua data lembur yang ada di database
$qLembur = $conn->query("SELECT id, user_id, total_menit, total_upah FROM lembur");
$count = 0;

while($row = $qLembur->fetch_assoc()) {
    $uid = $row['user_id'];
    
    // Kalau user tidak ditemukan atau tarifnya 0, skip
    if (!isset($users[$uid]) || $users[$uid]['rate'] <= 0) continue;
    
    $rate_sekarang = $users[$uid]['rate'];
    $menit = (float)$row['total_menit'];
    
    // 3. HITUNG ULANG SESUAI TARIF BARU
    // Rumus: (Menit / 60) * Tarif User
    $upah_baru = ceil(($menit / 60) * $rate_sekarang);
    
    // 4. Update Database jika angkanya beda
    // (Misal dulu tersimpan 10.000, padahal harusnya 50.000)
    if ((float)$row['total_upah'] != $upah_baru) {
        $id_lembur = $row['id'];
        
        // Update ke database
        $conn->query("UPDATE lembur SET total_upah = $upah_baru WHERE id = $id_lembur");
        
        echo "[FIXED] ID Lembur: $id_lembur | User: " . $users[$uid]['nama'] . "\n";
        echo "        Dulu: Rp " . number_format($row['total_upah']) . " -> Sekarang: Rp " . number_format($upah_baru) . "\n";
        $count++;
    }
}

echo "\n=== SELESAI. TOTAL DATA DIPERBAIKI: $count ===";
?>