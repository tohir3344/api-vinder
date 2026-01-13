<?php
declare(strict_types=1);
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/../Koneksi.php';

// Normalisasi variabel koneksi: terima $koneksi atau $conn
$DB = null;
if (isset($koneksi) && $koneksi instanceof mysqli) {
    $DB = $koneksi;
} elseif (isset($conn) && $conn instanceof mysqli) {
    $DB = $conn;
}

if (!$DB) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Koneksi DB tidak ditemukan"]);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    echo json_encode(["success" => false, "message" => "ID user diperlukan"]);
    exit;
}

/**
 * Update hanya kolom yang dikirim & tidak kosong.
 * Jika field tidak dikirim atau string kosong -> biarkan nilai lama.
 */
$fields = [
    "username"      => $_POST['username']      ?? null,
    "password"      => $_POST['password']      ?? null,
    "nama_lengkap"  => $_POST['nama_lengkap']  ?? null,
    "tempat_lahir"  => $_POST['tempat_lahir']  ?? null,
    "tanggal_lahir" => $_POST['tanggal_lahir'] ?? null,
    "no_telepon"    => $_POST['no_telepon']    ?? null,
    "alamat"        => $_POST['alamat']        ?? null,
    "gaji"          => isset($_POST['gaji']) ? $_POST['gaji'] : null,
    "lembur"        => isset($_POST['lembur']) ? $_POST['lembur'] : null, // TAMBAHAN: lembur
];

$setParts = [];
$params   = [];
$types    = "";

foreach ($fields as $col => $val) {
    if ($val === null) continue;                                 // tidak dikirim
    
    // TAMBAHAN: tambahkan pengecekan $col !== "lembur" di sini
    if ($col !== "gaji" && $col !== "lembur" && is_string($val) && trim($val) === "") continue; 

    // TAMBAHAN: Logika gaji sekarang juga berlaku untuk lembur
    if ($col === "gaji" || $col === "lembur") {
        // Normalisasi numerik: hilangkan non-digit
        $num = preg_replace('/[^\d.-]/', '', (string)$val);
        if ($num === "" || !is_numeric($num)) {
            continue;
        }
        $val = (int)$num; 
        $setParts[] = "$col = ?";
        $params[]   = $val;
        $types      .= "i"; 
        continue;
    }

    $setParts[] = "$col = ?";
    $params[]   = $val;
    $types      .= "s";
}

if (empty($setParts)) {
    echo json_encode(["success" => true, "message" => "Tidak ada perubahan"]);
    exit;
}

$sql = "UPDATE users SET " . implode(", ", $setParts) . " WHERE id = ?";
$types .= "i";
$params[] = $id;

$stmt = $DB->prepare($sql);
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Prepare gagal"]);
    exit;
}

$stmt->bind_param($types, ...$params);

try {
    $ok = $stmt->execute();
    if ($ok) {
        echo json_encode(["success" => true, "message" => "Data user berhasil diperbarui"]);
        $stmt->close();
        exit;
    } else {
        echo json_encode(["success" => false, "message" => "Gagal memperbarui data"]);
        $stmt->close();
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error: ".$e->getMessage()]);
    if ($stmt) { $stmt->close(); }
    exit;
}