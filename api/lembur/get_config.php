<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';
require_once __DIR__ . '/../config_lembur.php'; // berisi LE_START_CUTOFF, LE_END_CUTOFF, (opsional) rate
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Normalisasi ke HH:MM:SS
function norm_hms($v) {
  if (!$v) return null;
  $s = trim((string)$v);
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $s)) return $s;
  if (preg_match('/^\d{2}:\d{2}$/', $s)) return $s . ':00';
  return $s; // fallback, biar keliatan kalau konfig salah
}

$data = [
  'LE_START_CUTOFF' => norm_hms(defined('LE_START_CUTOFF') ? LE_START_CUTOFF : null),
  'LE_END_CUTOFF'   => norm_hms(defined('LE_END_CUTOFF')   ? LE_END_CUTOFF   : null),
  'RATE'            => defined('LE_RATE_PER_MENIT') ? (float)LE_RATE_PER_MENIT : null,
];

// alias supaya jelas di FE
$data['JAM_MASUK_PATOKAN']  = $data['LE_START_CUTOFF'];
$data['JAM_PULANG_PATOKAN'] = $data['LE_END_CUTOFF'];

echo json_encode(['success'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE);
