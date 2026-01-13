<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

// Panggil koneksi database
include 'Koneksi.php';

// Ambil data dari aplikasi
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

// Cek User di Database
$sql = "SELECT * FROM users WHERE username = '$username' AND password = '$password'";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    
    // INI KUNCINYA: Kirim ID dan Tarif Lembur ke HP
    $userData = [
        'id'           => $row['id'],            // Wajib ada buat simpan lembur
        'username'     => $row['username'],
        'nama_lengkap' => $row['nama_lengkap'],
        'role'         => $row['role'],
        'lembur'       => $row['lembur'],        // Wajib ada buat hitung duit
        'foto'         => $row['foto']
    ];

    echo json_encode([
        "success" => true,
        "message" => "Login Berhasil",
        "data"    => $userData
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Username atau Password salah!"
    ]);
}
?>