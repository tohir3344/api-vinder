<?php
// FILE: api/lembur/save_lembur_over.php

error_reporting(0); 
ini_set('display_errors', 0);
header('Content-Type: application/json');

include '../Koneksi.php';

// Cek koneksi database
if (!isset($conn) || !$conn) {
    echo json_encode(["success" => false, "message" => "Database tidak terhubung"]);
    exit;
}

// Ambil data dari request
$user_id     = $_POST['user_id'] ?? '';
$tanggal     = $_POST['tanggal'] ?? date('Y-m-d');
$jam_mulai   = $_POST['jam_mulai'] ?? "20:00"; 
$jam_selesai = $_POST['jam_selesai'] ?? date('H:i');
$keterangan  = $_POST['keterangan'] ?? '-';
$total_jam   = $_POST['total_jam'] ?? 0;
$total_menit = $_POST['total_menit'] ?? 0;

// Validasi User ID
if ($user_id === '') {
    echo json_encode(["success" => false, "message" => "User ID kosong/tidak valid."]);
    exit;
}

// --- PERBAIKAN: Hitung Tarif Lembur ---
// Default tarif jika data tidak ditemukan
$tarif_dasar = 20000; 

try {
    $sql_user = "SELECT * FROM users WHERE id = '$user_id'";
    $res_user = $conn->query($sql_user);
    
    if ($res_user && $res_user->num_rows > 0) {
        $row = $res_user->fetch_assoc();
        
        // Cek prioritas: Kolom 'lembur' -> Hitung dari 'gaji_pokok'
        if (isset($row['lembur'])) {
            $tarif_dasar = $row['lembur'];
        } else if (isset($row['gaji_pokok'])) {
            $tarif_dasar = floor($row['gaji_pokok'] / 173);
        }
    }
} catch (Exception $e) {
    // Fallback ke default jika query gagal
    $tarif_dasar = 20000; 
}

// Hitung total upah lembur (Tarif x 2)
$tarif_over  = $tarif_dasar * 2;
$total_upah  = $total_jam * $tarif_over;

// Upload Foto Bukti
$foto_nama = null;
if (isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] == 0) {
    $target_dir = "../../uploads/lembur/"; 
    if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); }
    
    $ext = pathinfo($_FILES['foto_bukti']['name'], PATHINFO_EXTENSION);
    $foto_nama = "over_" . $user_id . "_" . time() . "." . $ext;
    
    move_uploaded_file($_FILES['foto_bukti']['tmp_name'], $target_dir . $foto_nama);
}

// Hitung Waktu Masuk & Keluar (Support Lintas Hari)
$start = strtotime("$tanggal $jam_mulai");
$end   = strtotime("$tanggal $jam_selesai");

// Jika jam selesai lebih kecil dari jam mulai, anggap hari berikutnya
if ($end < $start) { 
    $end = strtotime("$tanggal $jam_selesai +1 day"); 
} 

$dt_masuk  = date('Y-m-d H:i:s', $start);
$dt_keluar = date('Y-m-d H:i:s', $end);

// Simpan Data ke Database
$sql = "INSERT INTO lembur (
            user_id, tanggal, jam_masuk, jam_keluar, 
            alasan, total_menit, total_jam, total_upah, 
            foto_bukti, jenis_lembur, status
        ) VALUES (
            '$user_id', '$tanggal', '$dt_masuk', '$dt_keluar',
            '$keterangan', '$total_menit', '$total_jam', '$total_upah',
            '$foto_nama', 'over', 'pending'
        )";

if (mysqli_query($conn, $sql)) {
    // Update Jam Keluar di Tabel Absen Harian
    $sql_absen = "UPDATE absen SET jam_keluar = '20:00:00' 
                  WHERE user_id = '$user_id' AND tanggal = '$tanggal'";
    mysqli_query($conn, $sql_absen);

    echo json_encode(["success" => true, "message" => "Lembur Over Berhasil Disimpan!"]);
} else {
    echo json_encode(["success" => false, "message" => "Gagal Simpan: " . mysqli_error($conn)]);
}
?>