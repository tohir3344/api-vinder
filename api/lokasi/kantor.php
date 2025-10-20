<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../koneksi.php';

$r = $conn->query("SELECT id, nama, lat, lng, radius_m FROM lokasi_kantor ORDER BY id LIMIT 1")->fetch_assoc();
echo json_encode(['success'=>true,'data'=>$r]);
    