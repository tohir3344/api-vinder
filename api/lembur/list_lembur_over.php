<?php
// Mencegah error PHP muncul sebagai teks HTML yang merusak JSON
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../Koneksi.php';

// Cek koneksi database
if (!isset($conn) || !$conn) {
    echo json_encode(["success" => false, "message" => "Database tidak terhubung", "data" => []]);
    exit;
}

// Ambil parameter user_id jika ada (untuk history staff)
$where_user = "";
if (isset($_GET['user_id'])) {
    $uid = mysqli_real_escape_string($conn, $_GET['user_id']);
    $where_user = " AND l.user_id = '$uid' ";
}

// Query ambil data
$sql = "SELECT l.*, u.nama_lengkap, u.lembur as tarif_lembur 
        FROM lembur l 
        JOIN users u ON l.user_id = u.id 
        WHERE LOWER(l.jenis_lembur) LIKE '%over%' $where_user
        ORDER BY 
            CASE WHEN LOWER(l.status) = 'pending' THEN 1 ELSE 2 END ASC, 
            l.tanggal DESC, 
            l.jam_masuk DESC";

$result = $conn->query($sql);
$data = array();

if ($result) {
    while($row = $result->fetch_assoc()) {
        // 1. Normalisasi status (Pending -> pending)
        $row['status'] = strtolower($row['status']); 
        
        // 2. Pastikan Tarif tidak NULL
        if (empty($row['tarif_lembur'])) {
            $row['tarif_lembur'] = 0; 
        }

        // 3. Pastikan Jam tidak NULL (PENTING BIAR FRONTEND TIDAK CRASH)
        if (is_null($row['jam_selesai'])) $row['jam_selesai'] = "";
        if (is_null($row['jam_keluar'])) $row['jam_keluar'] = "";

        $data[] = $row;
    }
    echo json_encode(["success" => true, "data" => $data]);
} else {
    echo json_encode(["success" => false, "message" => "Error SQL: " . $conn->error, "data" => []]);
}
?>