<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 0);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // ====== DB ======
    $DB_NAME = "penggajian_db";
    $conn = new mysqli("localhost", "root", "", $DB_NAME);
    $conn->set_charset('utf8mb4');

    // ====== BASE URL publik (untuk respon) ======
    $BASE_APP = "http://192.168.1.11/penggajian/";      // keluar → /uploads/
    $BASE_API = "http://192.168.1.11/penggajian/api/";  // masuk  → /uploads/absen/

    // ====== Utils URL ======
    function join_url(string $base, string $path): string {
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
    function build_url_masuk(string $baseApi, ?string $val): ?string {
        if (!$val) return null;
        if (preg_match('#^https?://#i', $val)) return $val;
        $v = ltrim($val, '/');
        $v = preg_replace('#^(api/)?uploads/absen/#i', 'uploads/absen/', $v);
        if (!preg_match('#^uploads/absen/#i', $v)) {
            $v = preg_replace('#^uploads/#i', '', $v);
            $v = 'uploads/absen/' . $v;
        }
        return join_url($baseApi, $v);
    }
    function build_url_keluar(string $baseApp, ?string $val): ?string {
        if (!$val) return null;
        if (preg_match('#^https?://#i', $val)) return $val;
        $v = ltrim($val, '/');
        $v = preg_replace('#^(api/)?uploads/absen/#i', 'uploads/', $v);
        $v = preg_replace('#^api/uploads/#i', 'uploads/', $v);
        if (!preg_match('#^uploads/#i', $v)) $v = 'uploads/' . $v;
        return join_url($baseApp, $v);
    }

    // ====== Helpers schema ======
    function table_exists(mysqli $c, string $db, string $t): bool {
        $sql = "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$c->real_escape_string($db)}' AND TABLE_NAME='{$c->real_escape_string($t)}' LIMIT 1";
        $res = $c->query($sql); return (bool)$res->fetch_row();
    }
    function column_exists(mysqli $c, string $db, string $t, string $col): bool {
        $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{$c->real_escape_string($db)}' AND TABLE_NAME='{$c->real_escape_string($t)}' AND COLUMN_NAME='{$c->real_escape_string($col)}' LIMIT 1";
        $res = $c->query($sql); return (bool)$res->fetch_row();
    }

    // ====== Deteksi tabel/kolom users (untuk nama) ======
    $userTable = null;
    foreach (['users','user'] as $t) if (table_exists($conn, $DB_NAME, $t)) { $userTable = $t; break; }
    $userIdCols   = ['id','id_user'];
    $userNameCols = ['nama','nama_lengkap','fullname','full_name','name','username'];

    function get_user_name(mysqli $c, string $db, ?string $tbl, array $idCols, array $nameCols, $userId): ?string {
        if (!$tbl) return null;
        $idCol = null; foreach ($idCols as $x) if (column_exists($c, $db, $tbl, $x)) { $idCol = $x; break; }
        if (!$idCol) return null;
        $pieces = [];
        foreach ($nameCols as $n) if (column_exists($c,$db,$tbl,$n)) $pieces[] = "NULLIF(TRIM(`$n`),'')";
        if (!$pieces) return null;
        $select = "COALESCE(".implode(",", $pieces).") AS nx";
        $uid = $c->real_escape_string((string)$userId);
        $tblSafe = "`" . str_replace("`","``",$tbl) . "`";
        $idColSafe = "`" . str_replace("`","``",$idCol) . "`";
        $sql = "SELECT $select FROM $tblSafe WHERE $idColSafe='$uid' LIMIT 1";
        $res = $c->query($sql);
        if ($row = $res->fetch_assoc()) { $nx = trim((string)($row['nx'] ?? '')); return $nx ?: null; }
        return null;
    }

    // =====================================================================
    // POST => upload (mode=masuk|keluar)
    // =====================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header("Content-Type: application/json; charset=utf-8");

        $mode    = strtolower(trim($_POST['mode'] ?? ''));
        $userId  = $_POST['user_id'] ?? null;
        $absenId = $_POST['id_absen'] ?? null;
        $tanggal = $_POST['tanggal'] ?? null;

        if ($mode !== 'masuk' && $mode !== 'keluar') { http_response_code(400); echo json_encode(['error'=>"mode harus 'masuk' atau 'keluar'"]); exit; }
        if (!$userId) { http_response_code(400); echo json_encode(['error'=>"user_id wajib"]); exit; }
        if (!isset($_FILES['foto']) || !is_uploaded_file($_FILES['foto']['tmp_name'])) { http_response_code(400); echo json_encode(['error'=>"file 'foto' wajib"]); exit; }

        // Tanggal & waktu lokal
        $tz = new DateTimeZone('Asia/Jakarta');
        $now = new DateTime('now', $tz);
        if (!$tanggal) $tanggal = $now->format('Y-m-d');
        $jamNow = $now->format('H:i:s');

        // Target directory & kolom
        if ($mode === 'masuk') {
            $uploadDir  = __DIR__ . '/../uploads/absen/';    // /api/galeri/ -> ../uploads/absen/
            $prefix     = 'in_';
            $fotoColumn = 'foto_masuk';
            $jamColumn  = 'jam_masuk';
        } else {
            $uploadDir  = __DIR__ . '/../../uploads/';       // /api/galeri/ -> ../../uploads/
            $prefix     = 'out_';
            $fotoColumn = 'foto_keluar';
            $jamColumn  = 'jam_keluar';
        }
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            throw new RuntimeException("Gagal membuat folder upload.");
        }

        // Nama file
        $allow = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic','image/avif'=>'avif'];
        $mime = @mime_content_type($_FILES['foto']['tmp_name']) ?: '';
        $ext  = $allow[$mime] ?? strtolower(pathinfo($_FILES['foto']['name'] ?? 'jpg', PATHINFO_EXTENSION) ?: 'jpg');
        $fname = $prefix . date('Ymd_His') . '_' . mt_rand(1000,9999) . '.' . $ext;

        if (!move_uploaded_file($_FILES['foto']['tmp_name'], $uploadDir . $fname)) {
            http_response_code(400); echo json_encode(['error'=>'Gagal simpan file']); exit;
        }

        // Pastikan ada baris absen
        $absenRowId = null;
        if ($absenId && ctype_digit((string)$absenId)) {
            $st = $conn->prepare("SELECT id FROM absen WHERE id=?");
            $st->bind_param('i', $absenId); $st->execute(); $st->bind_result($fid);
            if ($st->fetch()) $absenRowId = (int)$fid; $st->close();
        }
        if (!$absenRowId) {
            $st = $conn->prepare("SELECT id FROM absen WHERE user_id=? AND tanggal=? LIMIT 1");
            $st->bind_param('is', $userId, $tanggal); $st->execute(); $st->bind_result($fid);
            if ($st->fetch()) $absenRowId = (int)$fid; $st->close();
        }
        if (!$absenRowId) {
            $st = $conn->prepare("INSERT INTO absen (user_id, tanggal) VALUES (?,?)");
            $st->bind_param('is', $userId, $tanggal); $st->execute(); $absenRowId = $st->insert_id; $st->close();
        }

        // Update kolom foto + jam
        $sql = "UPDATE absen SET {$fotoColumn}=?, {$jamColumn}=? WHERE id=?";
        $st = $conn->prepare($sql);
        $st->bind_param('ssi', $fname, $jamNow, $absenRowId);
        $st->execute(); $st->close();

        // URL publik untuk konfirmasi
        $publicUrl = ($mode === 'masuk')
            ? rtrim($BASE_API,'/').'/uploads/absen/'.$fname
            : rtrim($BASE_APP,'/').'/uploads/'.$fname;

        echo json_encode([
            'success'    => true,
            'mode'       => $mode,
            'id_absen'   => $absenRowId,
            'user_id'    => (int)$userId,
            'tanggal'    => $tanggal,
            'jam'        => $jamNow,
            'file'       => $fname,
            'public_url' => $publicUrl,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =====================================================================
    // GET => list galeri (SEMUA akun)
    // =====================================================================
    header("Content-Type: application/json; charset=utf-8");

    $laporanMasuk = [];
    $laporanKeluar = [];

    $sqlMasuk  = "SELECT id, user_id, tanggal, jam_masuk,  foto_masuk  AS foto_raw FROM absen WHERE foto_masuk  IS NOT NULL AND foto_masuk  <> '' ORDER BY id DESC";
    $sqlKeluar = "SELECT id, user_id, tanggal, jam_keluar, foto_keluar AS foto_raw FROM absen WHERE foto_keluar IS NOT NULL AND foto_keluar <> '' ORDER BY id DESC";

    if ($res = $conn->query($sqlMasuk)) {
        while ($row = $res->fetch_assoc()) {
            $row['foto_url'] = build_url_masuk($BASE_API, $row['foto_raw']);   // /api/uploads/absen/...
            $nm = get_user_name($conn, $DB_NAME, $userTable, ['id','id_user'], ['nama','nama_lengkap','fullname','full_name','name','username'], $row['user_id']);
            $row['nama'] = $nm ?? ('ID ' . $row['user_id']);
            unset($row['foto_raw']);
            $laporanMasuk[] = $row;
        }
    }
    if ($res = $conn->query($sqlKeluar)) {
        while ($row = $res->fetch_assoc()) {
            $row['foto_url'] = build_url_keluar($BASE_APP, $row['foto_raw']); // /uploads/...
            $nm = get_user_name($conn, $DB_NAME, $userTable, ['id','id_user'], ['nama','nama_lengkap','fullname','full_name','name','username'], $row['user_id']);
            $row['nama'] = $nm ?? ('ID ' . $row['user_id']);
            unset($row['foto_raw']);
            $laporanKeluar[] = $row;
        }
    }

    echo json_encode([
        'laporan_masuk'  => $laporanMasuk,
        'laporan_keluar' => $laporanKeluar,
        'meta' => ['user_table' => $userTable]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(['error' => 'Server error: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
}