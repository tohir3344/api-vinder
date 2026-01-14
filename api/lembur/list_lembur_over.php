<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../Koneksi.php';

// HAPUS filter "AND l.status = ..." supaya semua muncul.
// Gunakan ORDER BY CASE/FIELD supaya 'pending' muncul paling atas.

$sql = "SELECT l.*, u.nama_lengkap, u.lembur as tarif_lembur 
        FROM lembur l 
        JOIN users u ON l.user_id = u.id 
        WHERE l.jenis_lembur = 'over' 
        ORDER BY 
            CASE WHEN l.status = 'pending' THEN 1 ELSE 2 END ASC, -- Prioritaskan Pending
            l.tanggal DESC, -- Sisanya urutkan berdasarkan tanggal terbaru
            l.jam_masuk DESC";

$result = $conn->query($sql);

$data = array();
while($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode(["success" => true, "data" => $data]);
?>