<?php
declare(strict_types=1);

// 1. Set Timezone Indonesia (PENTING biar sinkron sama user)
date_default_timezone_set('Asia/Jakarta');

// Matikan display_errors supaya respon JSON tetap bersih
error_reporting(E_ALL);
ini_set('display_errors', '0'); 
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../php_error.log');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/../Koneksi.php';

// 2. Ambil User ID & Tanggal
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
// Jika frontend kirim tanggal, pakai itu. Jika tidak, pakai hari ini (WIB).
$tgl = isset($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');

if ($user_id <= 0) { 
    http_response_code(400); 
    echo json_encode(['success'=>false, 'message'=>'user_id wajib']); 
    exit; 
}

// 3. Query Data Absen Hari Ini
// Kita ambil id, tanggal, jam_masuk, jam_keluar
$stmt = $conn->prepare("SELECT id, tanggal, jam_masuk, jam_keluar FROM absen WHERE user_id=? AND tanggal=? LIMIT 1");
$stmt->bind_param("is", $user_id, $tgl);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

// 4. Siapkan Data Response
// Jika belum absen sama sekali, kita kirim null struktur standar
if ($row) {
    $data = $row;
} else {
    $data = [
        'id' => null,
        'tanggal' => $tgl, 
        'jam_masuk' => null, 
        'jam_keluar' => null
    ];
}

// 5. Kirim JSON
echo json_encode([
  'success' => true,
  'data'    => $data
]);

$stmt->close();
// $conn->close();
?>