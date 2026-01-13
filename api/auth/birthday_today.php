<?php
// api/auth/birthday_today.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

// Sesuaikan path ke Koneksi.php
require_once __DIR__ . '/../Koneksi.php';

date_default_timezone_set('Asia/Jakarta');

try {
    // Query cari user yang Bulan & Tanggal lahirnya == Hari Ini
    // Kita abaikan tahunnya (cuma cek tanggal & bulan)
    // Pastikan nama tabelnya 'users' d  an kolomnya 'nama_lengkap' & 'tanggal_lahir'
    
    $sql = "SELECT nama_lengkap 
            FROM users 
            WHERE MONTH(tanggal_lahir) = MONTH(CURRENT_DATE()) 
              AND DAY(tanggal_lahir) = DAY(CURRENT_DATE())";

    $result = $conn->query($sql);

    $names = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Format nama biar rapi (Huruf Besar Awal Tiap Kata)
            // Contoh: "RHEZA RIFALSYA" jadi "Rheza Rifalsya"
            $names[] = trim(ucwords(strtolower($row['nama_lengkap'])));
        }
    }

    echo json_encode([
        "success" => true,
        "has_birthday" => count($names) > 0,
        "names" => $names
    ]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>