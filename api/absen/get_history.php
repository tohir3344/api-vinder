<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../php_error.log');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

header("Content-Type: application/json; charset=utf-8");
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    // === KONEKSI (perhatikan case filename) ===
    $connPath = __DIR__ . '/../Koneksi.php'; // ganti ke 'koneksi.php' kalau filenya huruf kecil
    if (!file_exists($connPath)) {
        http_response_code(500);
        echo json_encode(["success"=>false,"message"=>"Koneksi.php not found (check filename case)."]);
        exit;
    }
    /** @var mysqli $conn */
    require_once $connPath;

    // === Helper: cek kolom ada/tidak ===
    function column_exists(mysqli $conn, string $db, string $table, string $col): bool {
        $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
        $st  = $conn->prepare($sql);
        $st->bind_param('sss', $db, $table, $col);
        $st->execute();
        $res = $st->get_result();
        $ok  = (bool)$res->fetch_row();
        $st->close();
        return $ok;
    }

    // ambil nama database aktif
    $dbres = $conn->query("SELECT DATABASE() AS db");
    $dbrow = $dbres ? $dbres->fetch_assoc() : null;
    $dbname = $dbrow ? $dbrow['db'] : '';

    // asumsi nama tabel user "users" (kalau di proyekmu "user", kita coba keduanya)
    $userTable = null;
    foreach (['users','user'] as $cand) {
        $chk = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
        $chk->bind_param('ss', $dbname, $cand);
        $chk->execute();
        $has = (bool)$chk->get_result()->fetch_row();
        $chk->close();
        if ($has) { $userTable = $cand; break; }
    }
    if ($userTable === null) {
        http_response_code(500);
        echo json_encode(["success"=>false,"message"=>"Tabel users/user tidak ditemukan."]);
        exit;
    }

    // deteksi kolom nama yang tersedia
    $nameCandidates = ['nama','name','username','full_name','display_name'];
    $nameCol = null;
    foreach ($nameCandidates as $c) {
        if (column_exists($conn, $dbname, $userTable, $c)) { $nameCol = $c; break; }
    }
    // email opsional; kalau tidak ada, kita isi string kosong di SELECT
    $hasEmail = column_exists($conn, $dbname, $userTable, 'email');

    // === PARAMS ===
    $q     = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $start = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : null; // YYYY-MM-DD
    $end   = isset($_GET['end'])   && $_GET['end']   !== '' ? $_GET['end']   : null; // YYYY-MM-DD
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 300;
    if ($limit <= 0 || $limit > 1000) $limit = 300;

    // default range 30 hari
    if (!$start && !$end) {
        $start = date('Y-m-d', strtotime('-30 days'));
        $end   = date('Y-m-d');
    }

    $where  = [];
    $params = [];
    $types  = '';

    // filter q → ke kolom nama yang terdeteksi + email (kalau ada)
    if ($q !== '') {
        if ($nameCol !== null && $hasEmail) {
            $where[] = "(u.`$nameCol` LIKE CONCAT('%', ?, '%') OR u.`email` LIKE CONCAT('%', ?, '%'))";
            $params[] = $q; $params[] = $q; $types .= 'ss';
        } elseif ($nameCol !== null) {
            $where[] = "(u.`$nameCol` LIKE CONCAT('%', ?, '%'))";
            $params[] = $q; $types .= 's';
        } elseif ($hasEmail) {
            $where[] = "(u.`email` LIKE CONCAT('%', ?, '%'))";
            $params[] = $q; $types .= 's';
        }
    }

    if ($start && $end) {
        $where[] = "(a.tanggal BETWEEN ? AND ?)";
        $params[] = $start; $params[] = $end; $types .= 'ss';
    } elseif ($start) {
        $where[] = "(a.tanggal >= ?)";
        $params[] = $start; $types .= 's';
    } elseif ($end) {
        $where[] = "(a.tanggal <= ?)";
        $params[] = $end; $types .= 's';
    }

    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

    $selectNama  = $nameCol ? "u.`$nameCol` AS nama" : "CAST('' AS CHAR) AS nama";
    $selectEmail = $hasEmail ? "u.`email` AS email" : "CAST('' AS CHAR) AS email";

    // 🔥🔥 BAGIAN INI YANG BERUBAH TOTAL BRAY 🔥🔥
    $sql = "
      SELECT
        a.id,
        a.user_id,
        $selectNama,
        $selectEmail,
        a.tanggal,
        a.jam_masuk,
        a.jam_keluar,
        a.status        AS keterangan,
        
        -- LOGIC PINTAR: Pilih alasan dari tabel yang tepat
        CASE 
            WHEN a.status = 'HADIR' THEN COALESCE(l.alasan, a.alasan)
            ELSE a.alasan 
        END AS alasan,

        -- Ambil alasan_keluar dari tabel lembur
        l.alasan_keluar,
        
        a.masuk_lat, a.masuk_lng,
        a.keluar_lat, a.keluar_lng,
        a.foto_masuk, a.foto_keluar
      FROM absen a
      JOIN `{$userTable}` u ON u.id = a.user_id
      
      -- LEFT JOIN ke tabel lembur biar bisa ambil datanya
      LEFT JOIN lembur l ON l.user_id = a.user_id AND l.tanggal = a.tanggal
      
      $whereSql
      ORDER BY a.tanggal DESC, a.jam_masuk DESC, a.id DESC
      LIMIT $limit
    ";

    $stmt = $conn->prepare($sql);
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();

    echo json_encode([
        "success" => true,
        "count"   => count($rows),
        "data"    => $rows
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error"   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}