<?php
// api/lembur/subtotal_list.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Koneksi.php'; 
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function j_ok($data){ echo json_encode(['success'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
function j_bad($msg,$http=200){ http_response_code($http); echo json_encode(['success'=>false,'message'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

if (!isset($conn) || !($conn instanceof mysqli)) j_bad('Koneksi database tidak tersedia', 500);
$conn->set_charset('utf8mb4');

/* ===== Ambil input GET/POST ===== */
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: 'null', true);
$q = array_merge($_GET ?? [], is_array($body) ? $body : []);

$type   = strtoupper(trim((string)($q['type'] ?? 'WEEK')));
$userId = isset($q['user_id']) ? (int)$q['user_id'] : null;

/* ===== Tentukan Periode ===== */
function toYmd($d){ return date('Y-m-d', $d); }
$today = time();

if ($type === 'MONTHLY' || $type === 'MONTH') {
    $type = 'MONTH';
    $monthStr = trim((string)($q['month'] ?? '')); // "YYYY-MM"
    if ($monthStr && preg_match('/^\d{4}-\d{2}$/', $monthStr)) {
        $start = strtotime($monthStr . '-01 00:00:00');
    } else {
        $start = strtotime(date('Y-m-01 00:00:00', $today));
    }
    $end = strtotime(date('Y-m-t 23:59:59', $start));
    $periodeType = 'MONTH';
} else {
    // WEEK
    $type = 'WEEK';
    $startParam = trim((string)($q['start'] ?? ''));
    $endParam   = trim((string)($q['end'] ?? ''));
    
    // FIX TYPO REGEX DISINI (\d{2})
    if ($startParam && $endParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endParam)) {
        $start = strtotime($startParam . ' 00:00:00');
        $end   = strtotime($endParam   . ' 23:59:59');
    } else {
        $dow = (int)date('N', $today); 
        $monday = strtotime('-' . ($dow - 1) . ' days', $today);
        $start  = strtotime(date('Y-m-d 00:00:00', $monday));
        $end    = strtotime(date('Y-m-d 23:59:59', strtotime('+6 days', $start)));
    }
    $periodeType = 'WEEK';
}

$startStr = toYmd($start);
$endStr   = toYmd($end);

/* ===== Query Data Subtotal ===== */
// Data diambil dari tabel 'subtotal_upah_user' yang diisi oleh 'rekap_generate.php'
$where = 's.periode_type = ? AND s.periode_start = ? AND s.periode_end = ?';
$params = [$periodeType, $startStr, $endStr];
$types  = 'sss';

if ($userId) { 
    $where .= ' AND s.user_id = ?'; 
    $params[] = $userId; 
    $types .= 'i'; 
}

$sql = "
    SELECT 
        s.id, 
        s.user_id, 
        COALESCE(u.nama_lengkap, u.username, CONCAT('User#', s.user_id)) AS nama,
        COALESCE(u.lembur, 0) AS rate_per_jam,
        s.periode_type, 
        s.periode_start, 
        s.periode_end, 
        s.subtotal_upah,
        s.created_at, 
        s.updated_at
    FROM subtotal_upah_user s
    LEFT JOIN users u ON u.id = s.user_id
    WHERE $where
    ORDER BY nama ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$total = 0.0;

while ($r = $res->fetch_assoc()) {
    $rows[] = [
        'id'            => (int)$r['id'],
        'user_id'       => (int)$r['user_id'],
        'nama'          => (string)$r['nama'],
        'rate_per_jam'  => (int)$r['rate_per_jam'],
        'periode_type'  => (string)$r['periode_type'],
        'periode_start' => (string)$r['periode_start'],
        'periode_end'   => (string)$r['periode_end'],
        'subtotal_upah' => (float)$r['subtotal_upah'], // Ini hasil kalkulasi dari rekap_generate.php
        'created_at'    => (string)$r['created_at'],
        'updated_at'    => (string)$r['updated_at'],
    ];
    $total += (float)$r['subtotal_upah'];
}
$stmt->close();

j_ok([
    'type'   => $periodeType,
    'start'  => $startStr,
    'end'    => $endStr,
    'info'   => 'Data diambil dari subtotal_upah_user',
    'count'  => count($rows),
    'total'  => round($total, 2),
    'rows'   => $rows,
]);
?>