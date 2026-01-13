<?php
// api/auth/change_password.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/../Koneksi.php';

$input = json_decode(file_get_contents('php://input'), true);
$user_id = $input['user_id'] ?? 0;
$old_pass = $input['old_password'] ?? '';
$new_pass = $input['new_password'] ?? '';

if (!$user_id || !$old_pass || !$new_pass) {
    echo json_encode(["success" => false, "message" => "Data tidak lengkap"]);
    exit;
}

// 1. Cek Password Lama
$q = $conn->query("SELECT password FROM users WHERE id = $user_id");
$r = $q->fetch_assoc();

if (!$r) {
    echo json_encode(["success" => false, "message" => "User tidak ditemukan"]);
    exit;
}

// Kalau password di DB belum di-hash (plain text), sesuaikan logikanya.
// Di sini asumsi password di DB = input (plain text)
if ($r['password'] !== $old_pass) {
    echo json_encode(["success" => false, "message" => "Password lama salah"]);
    exit;
}

// 2. Update Password Baru
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param("si", $new_pass, $user_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Password berhasil diubah"]);
} else {
    echo json_encode(["success" => false, "message" => "Gagal update password"]);
}
?>