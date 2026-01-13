<?php
// auth/forgot_password.php

header('Content-Type: application/json; charset=utf-8');

// (opsional, kalau perlu CORS)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../Koneksi.php';

// helper singkat buat respon JSON
function json_response($success, $payload = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success], $payload));
    exit;
}

// ambil body JSON
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$email = isset($data['email']) ? trim($data['email']) : '';

if ($email === '') {
    json_response(false, [
        'message' => 'Email wajib diisi.',
    ], 400);
}

// (opsional) validasi format email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(false, [
        'message' => 'Format email tidak valid.',
    ], 400);
}

// ambil username & password dari tabel users
// SESUAIKAN nama tabel / kolom jika berbeda
$sql = "SELECT username, password FROM users WHERE email = ? LIMIT 1";

if (!$stmt = $conn->prepare($sql)) {
    json_response(false, [
        'message' => 'Gagal menyiapkan query.',
        'error'   => $conn->error,
    ], 500);
}

$stmt->bind_param('s', $email);

if (!$stmt->execute()) {
    json_response(false, [
        'message' => 'Gagal menjalankan query.',
        'error'   => $stmt->error,
    ], 500);
}

$result = $stmt->get_result();

if (!$row = $result->fetch_assoc()) {
    // email tidak ditemukan
    json_response(false, [
        'message' => 'Email anda tidak terdaftar.',
    ], 404);
}

// ⚠️ Di titik ini diasumsikan password di DB disimpan plaintext.
// Jika password sudah di-hash, TIDAK BISA dikembalikan, harus pakai fitur reset.

json_response(true, [
    'data' => [
        'username' => $row['username'],
        'password' => $row['password'],
    ],
]);
