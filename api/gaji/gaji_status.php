<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../Koneksi.php';

$mysqli = null;
if (isset($conn) && $conn instanceof mysqli) $mysqli = $conn;
elseif (isset($db) && $db instanceof mysqli) $mysqli = $db;

if (!$mysqli) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Koneksi DB tidak ditemukan"]);
    exit;
}
$mysqli->set_charset('utf8mb4');

try {
    $raw = file_get_contents('php://input');
    if (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $in = json_decode($raw, true) ?: [];
    } else {
        $in = $_POST ?: [];
    }

    // SINKRONISASI: Terima ID tunggal maupun Array ID (untuk massal)
    $idsRaw = isset($in['id']) ? $in['id'] : null;
    $status = isset($in['status_bayar']) ? (string)$in['status_bayar'] : '';

    if (empty($idsRaw)) {
        throw new Exception("Param id wajib");
    }

    // Normalisasi ID menjadi array (supaya logic IN di SQL jalan)
    $ids = is_array($idsRaw) ? $idsRaw : [$idsRaw];
    
    // Bersihkan ID (pastikan integer)
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function($v) { return $v > 0; });

    if (empty($ids)) {
        throw new Exception("ID tidak valid");
    }

    if ($status !== 'pending' && $status !== 'paid') {
        throw new Exception("status_bayar harus 'pending' atau 'paid'");
    }

    // Buat placeholder (?,?,?) sesuai jumlah ID
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $sql = "
        UPDATE gaji_run 
        SET status_bayar = ?, 
            paid_at = IF(? = 'paid', NOW(), NULL)
        WHERE id IN ($placeholders)
    ";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare gagal: " . $mysqli->error);
    }

    // Bind parameter secara dinamis
    // Parameter pertama: status, status, baru daftar IDs
    $bindParams = array_merge([$status, $status], $ids);
    $stmt->bind_param("ss" . $types, ...$bindParams);
    
    $stmt->execute();

    // Berhasil jika baris terupdate atau memang sudah berstatus tersebut
    echo json_encode([
        "success" => true,
        "message" => count($ids) . " slip berhasil diperbarui",
        "data" => [
            "ids" => $ids,
            "status_bayar" => $status,
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400); // 400 lebih cocok untuk error parameter
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}