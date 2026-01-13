    <?php
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json");

    include '../Koneksi.php';

    // Query Join ke tabel Users biar dapet Nama Lengkap
    $sql = "SELECT l.*, u.nama_lengkap, u.lembur as tarif_lembur 
            FROM lembur l 
            JOIN users u ON l.user_id = u.id 
            WHERE l.jenis_lembur = 'over' 
            AND l.status = 'pending' 
            ORDER BY l.tanggal DESC, l.jam_masuk DESC";

    $result = $conn->query($sql);

    $data = array();
    while($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode(["success" => true, "data" => $data]);
    ?>