<?php
// api/izin/izin_list.php
declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';

/** Cek apakah kolom ada di tabel */
function has_column(mysqli $conn, string $table, string $column): bool {
  $db = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? '';
  $stmt = $conn->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $stmt->bind_param("sss", $db, $table, $column);
  $stmt->execute();
  $ok = (bool) $stmt->get_result()->fetch_row();
  $stmt->close();
  return $ok;
}

try {
  // ==== DETEKSI KOLOM ====
  // tanggal mulai/selesai bisa 'mulai'/'selesai' ATAU 'tanggal_mulai'/'tanggal_selesai'
  $colMulai   = has_column($conn, 'izin', 'mulai')            ? 'mulai'
              : (has_column($conn, 'izin', 'tanggal_mulai')   ? 'tanggal_mulai'   : null);
  $colSelesai = has_column($conn, 'izin', 'selesai')          ? 'selesai'
              : (has_column($conn, 'izin', 'tanggal_selesai') ? 'tanggal_selesai' : null);
  if (!$colMulai || !$colSelesai) {
    throw new Exception("Kolom tanggal tidak ditemukan. Harus ada salah satu pasangan: (mulai, selesai) atau (tanggal_mulai, tanggal_selesai).");
  }

  // kolom alasan/keterangan/status hampir selalu ada; jika beda, silakan mapping di sini
  $colAlasan      = has_column($conn, 'izin', 'alasan') ? 'alasan' : null;
  $colKeterangan  = has_column($conn, 'izin', 'keterangan') ? 'keterangan' : null;
  $colStatus      = has_column($conn, 'izin', 'status') ? 'status' : null;
  if (!$colStatus) throw new Exception("Kolom status tidak ditemukan di tabel izin.");

  // di tabel users, pakai 'nama_lengkap' jika ada, kalau tidak pakai 'name' atau fallback kosong
  $userNameCol = has_column($conn, 'users', 'nama_lengkap') ? 'nama_lengkap' :
                 (has_column($conn, 'users', 'name') ? 'name' : null);

  // ==== PARAM ====
  $q       = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
  $status  = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
  $dari    = isset($_GET['dari']) ? trim((string)$_GET['dari']) : '';
  $sampai  = isset($_GET['sampai']) ? trim((string)$_GET['sampai']) : '';
  $limit   = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
  $offset  = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

  $where = [];
  $bind  = [];
  $types = '';

  if ($q !== '') {
    // search di username, nama (kalau ada), dan alasan (kalau ada)
    $like = "%{$q}%";
    $parts = ["u.username LIKE ?"];
    $bind[] = $like; $types .= 's';
    if ($userNameCol) { $parts[] = "u.$userNameCol LIKE ?"; $bind[] = $like; $types .= 's'; }
    if ($colAlasan)   { $parts[] = "i.$colAlasan LIKE ?";   $bind[] = $like; $types .= 's'; }
    $where[] = '(' . implode(' OR ', $parts) . ')';
  }

  if ($status !== '' && $colStatus) {
    $where[] = "i.$colStatus = ?";
    $bind[]  = $status; $types .= 's';
  }

  // filter tanggal overlap: (mulai <= :sampai) AND (selesai >= :dari)
  if ($dari !== '' && $sampai !== '') {
    $where[] = "(i.$colMulai <= ? AND i.$colSelesai >= ?)";
    $bind[] = $sampai; $bind[] = $dari; $types .= 'ss';
  } elseif ($dari !== '') {
    $where[] = "i.$colSelesai >= ?";
    $bind[] = $dari; $types .= 's';
  } elseif ($sampai !== '') {
    $where[] = "i.$colMulai <= ?";
    $bind[] = $sampai; $types .= 's';
  }

  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  // ==== TOTAL ====
  $sqlCount = "
    SELECT COUNT(*) AS total
    FROM izin i
    JOIN users u ON u.id = i.user_id
    $whereSql
  ";
  $stmt = $conn->prepare($sqlCount);
  if ($types !== '') $stmt->bind_param($types, ...$bind);
  if (!$stmt->execute()) throw new Exception("SQL COUNT gagal: " . $stmt->error);
  $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
  $stmt->close();

  // ==== DATA ====
  // nama (opsional)
  $selectNama = $userNameCol ? ", u.$userNameCol AS nama" : ", '' AS nama";
  // alasan (opsional)
  $selectAlasan = $colAlasan ? ", i.$colAlasan AS alasan" : ", '' AS alasan";
  // keterangan (opsional)
  $selectKet = $colKeterangan ? ", i.$colKeterangan AS keterangan" : ", '' AS keterangan";

  $sqlData = "
    SELECT
      i.id, i.user_id,
      i.$colStatus AS status,
      DATE_FORMAT(i.$colMulai,   '%Y-%m-%d') AS mulai,
      DATE_FORMAT(i.$colSelesai, '%Y-%m-%d') AS selesai,
      i.created_at,
      u.username
      $selectNama
      $selectAlasan
      $selectKet
    FROM izin i
    JOIN users u ON u.id = i.user_id
    $whereSql
    ORDER BY i.created_at DESC, i.id DESC
    LIMIT ? OFFSET ?
  ";

  $stmt2 = $conn->prepare($sqlData);
  if (!$stmt2) throw new Exception("SQL DATA prepare gagal: " . $conn->error);

  if ($types !== '') {
    $types2 = $types . 'ii';
    $params = array_merge($bind, [$limit, $offset]);
    $stmt2->bind_param($types2, ...$params);
  } else {
    $stmt2->bind_param('ii', $limit, $offset);
  }

  if (!$stmt2->execute()) throw new Exception("SQL DATA exec gagal: " . $stmt2->error);

  $res = $stmt2->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    // durasi (inklusif)
    $mulai   = new DateTime($r['mulai']);
    $selesai = new DateTime($r['selesai']);
    $dur     = $mulai->diff($selesai)->days + 1;
    $r['durasi_hari'] = max(1, (int)$dur);
    $rows[] = $r;
  }
  $stmt2->close();

  echo json_encode(['success' => true, 'data' => $rows, 'total' => $total], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
