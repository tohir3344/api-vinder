<?php
// FILE: api/lembur/save_lembur_over.php

// Matikan error text HTML agar response tetap JSON murni
error_reporting(0); 
ini_set('display_errors', 0);
header('Content-Type: application/json');

include '../Koneksi.php';

// 1. Cek Koneksi
if (!isset($conn) || !$conn) {
    echo json_encode(["success" => false, "message" => "Database tidak terhubung"]);
    exit;
}

// 2. AMBIL DATA & SANITASI (PENTING! Agar aman dari hack & error kutip)
$user_id     = mysqli_real_escape_string($conn, $_POST['user_id'] ?? '');
$tanggal     = mysqli_real_escape_string($conn, $_POST['tanggal'] ?? date('Y-m-d'));
$jam_mulai   = mysqli_real_escape_string($conn, $_POST['jam_mulai'] ?? "20:00"); 
$jam_selesai = mysqli_real_escape_string($conn, $_POST['jam_selesai'] ?? date('H:i'));
$keterangan  = mysqli_real_escape_string($conn, $_POST['keterangan'] ?? '-');
$total_jam   = mysqli_real_escape_string($conn, $_POST['total_jam'] ?? 0);
$total_menit = mysqli_real_escape_string($conn, $_POST['total_menit'] ?? 0);

// Validasi ID
if ($user_id === '') {
    echo json_encode(["success" => false, "message" => "User ID kosong/tidak valid."]);
    exit;
}

// 3. LOGIKA TARIF (Lebih Teliti)
$tarif_dasar = 20000; // Default

try {
    $sql_user = "SELECT * FROM users WHERE id = '$user_id'";
    $res_user = $conn->query($sql_user);
    
    if ($res_user && $res_user->num_rows > 0) {
        $row = $res_user->fetch_assoc();
        
        // Cek prioritas: Kolom 'lembur' -> Hitung dari 'gaji_pokok'
        if (!empty($row['lembur']) && $row['lembur'] > 0) {
            $tarif_dasar = $row['lembur'];
        } else if (!empty($row['gaji_pokok']) && $row['gaji_pokok'] > 0) {
            $tarif_dasar = floor($row['gaji_pokok'] / 173);
        }
    }
} catch (Exception $e) {
    $tarif_dasar = 20000; 
}

// Hitung total upah (Tarif x 2) & Bulatkan ke atas
$tarif_over  = $tarif_dasar * 2;
$total_upah  = ceil($total_jam * $tarif_over);

// 4. UPLOAD FOTO
$foto_nama = null;
if (isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] == 0) {
    $target_dir = "../../uploads/lembur/"; 
    
    // Buat folder otomatis jika belum ada
    if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); }
    
    $ext = pathinfo($_FILES['foto_bukti']['name'], PATHINFO_EXTENSION);
    // Nama file unik: over_USERID_TIMESTAMP.jpg
    $foto_nama = "over_" . $user_id . "_" . time() . "." . $ext;
    
    move_uploaded_file($_FILES['foto_bukti']['tmp_name'], $target_dir . $foto_nama);
}

// 5. HITUNG WAKTU (Lintas Hari)
$start = strtotime("$tanggal $jam_mulai");
$end   = strtotime("$tanggal $jam_selesai");

// Jika jam selesai lebih kecil (misal: Mulai 20:00, Selesai 01:00 pagi)
if ($end < $start) { 
    $end = strtotime("$tanggal $jam_selesai +1 day"); 
} 

$dt_masuk  = date('Y-m-d H:i:s', $start);
$dt_keluar = date('Y-m-d H:i:s', $end);

// 6. SIMPAN DATA (INSERT)
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
    // 7. Update Jam Keluar Reguler jadi 20:00
    // Agar sistem absen harian tahu staff lanjut lembur over
    $sql_absen = "UPDATE absen SET jam_keluar = '20:00:00' 
                  WHERE user_id = '$user_id' AND tanggal = '$tanggal'";
    mysqli_query($conn, $sql_absen);

    echo json_encode(["success" => true, "message" => "Lembur Over Berhasil Disimpan!"]);
} else {
    echo json_encode(["success" => false, "message" => "Gagal Simpan Database: " . mysqli_error($conn)]);
}
?>