<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$host = 'localhost';  
$user = 'root';       
$pass = '';         
$db   = 'penggajian_db';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  $conn = new mysqli($host, $user, $pass, $db);
  $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([  
    'success' => false,
    'message' => 'Koneksi database gagal'
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
