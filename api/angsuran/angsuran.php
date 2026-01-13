<?php
declare(strict_types=1);

require_once __DIR__ . '/../Koneksi.php';  // perbaikan _DIR_
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$method = $_SERVER['REQUEST_METHOD'];

/** helper */
function jecho($arr, int $code = 200) {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  switch ($method) {
    /* =======================
     * GET: List angsuran
     * - Jika query ?user_id=XX → hanya data milik user itu (dengan nama_user)
     * - Jika tanpa user_id → semua (admin) + join username
     * ======================= */
    case 'GET': {
      $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

      if ($user_id > 0) {
        $sql = "SELECT a.*, u.username AS nama_user
                FROM angsuran a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.user_id = ?
                ORDER BY a.tanggal DESC, a.id DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
      } else {
        $sql = "SELECT a.*, u.username AS nama_user
                FROM angsuran a
                LEFT JOIN users u ON a.user_id = u.id
                ORDER BY a.tanggal DESC, a.id DESC";
        $stmt = $conn->prepare($sql);
      }

      $stmt->execute();
      $res = $stmt->get_result();
      $data = [];
      while ($row = $res->fetch_assoc()) {
        // Normalisasi angka (optional, biar rapi di FE)
        if (isset($row['nominal'])) $row['nominal'] = (float)$row['nominal'];
        if (isset($row['sisa']))    $row['sisa']    = is_null($row['sisa']) ? null : (float)$row['sisa'];
        $data[] = $row;
      }
      $stmt->close();

      jecho($data);
    }

    /* =======================
     * POST: Tambah pengajuan
     * body: { user_id, nominal, tanggal, keterangan? }
     * ======================= */
   // 🔹 POST: Tambah data baru
case 'POST':
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['user_id'], $input['nominal'])) {
        echo json_encode(["success" => false, "message" => "Data tidak lengkap"]);
        exit;
    }

    $user_id    = (int)$input['user_id'];
    $nominal    = (float)$input['nominal'];
    $keterangan = $input['keterangan'] ?? '';
    // tanggal otomatis (fallback hari ini)
    $tanggal    = isset($input['tanggal']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['tanggal'])
                  ? $input['tanggal'] : date('Y-m-d');

    // ❗CEK: Masih ada angsuran aktif? (sisa > 0 dan status <> 'ditolak')
    $cek = $conn->prepare("SELECT COUNT(*) AS cnt FROM angsuran WHERE user_id = ? AND COALESCE(sisa, nominal) > 0 AND (status IS NULL OR status <> 'ditolak')");
    $cek->bind_param("i", $user_id);
    $cek->execute();
    $cnt = (int)($cek->get_result()->fetch_assoc()['cnt'] ?? 0);
    $cek->close();

    if ($cnt > 0) {
        echo json_encode([
          "success" => false,
          "message" => "Masih ada angsuran yang belum lunas. Lunasi terlebih dahulu sebelum mengajukan lagi."
        ]);
        exit;
    }

    $sql = "INSERT INTO angsuran (user_id, nominal, sisa, keterangan, tanggal, status)
            VALUES (?, ?, ?, ?, ?, 'pending')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iddss", $user_id, $nominal, $nominal, $keterangan, $tanggal);
    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Pengajuan angsuran berhasil dikirim"]);
    } else {
        echo json_encode(["success" => false, "message" => "Gagal menambahkan data"]);
    }
    break;

    /* =======================
     * PUT: Update status / potongan
     * body:
     * - { id, status }        → update status (disetujui/ditolak)
     * - { id, potongan }      → kurangi sisa (tanpa riwayat di tabel lain)
     *   (Catatan: untuk riwayat idealnya insert ke angsuran_potongan)
     * ======================= */
    case 'PUT': {
      $input = json_decode(file_get_contents('php://input'), true) ?? [];
      if (!isset($input['id'])) {
        jecho(["success" => false, "message" => "Data tidak lengkap"], 400);
      }
      $id = (int)$input['id'];

      // --- Update potongan (kurangi sisa) ---
      if (isset($input['potongan'])) {
        $potongan = (float)$input['potongan'];

        // Ambil sisa sebelumnya (FOR UPDATE untuk safety kalau pakai InnoDB)
        $q = $conn->prepare("SELECT sisa FROM angsuran WHERE id = ? FOR UPDATE");
        $q->bind_param("i", $id);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();

        if (!$row) {
          jecho(["success" => false, "message" => "Data angsuran tidak ditemukan"], 404);
        }

        $sisa_sebelumnya = (float)($row['sisa'] ?? 0);

        if ($sisa_sebelumnya <= 0) {
          jecho(["success" => false, "message" => "Angsuran sudah lunas"], 400);
        }

        $sisa_baru = $sisa_sebelumnya - $potongan;
        if ($sisa_baru < 0) $sisa_baru = 0;

        // Update kolom sisa (+ optional kolom potongan terakhir bila ada)
        // NOTE: kalau kolom potongan tidak ada di tabel, ganti jadi hanya sisa & tanggal.
        $hasPotonganColumn = true; // set ke false kalau tabel kamu tidak punya kolom potongan

        if ($hasPotonganColumn) {
          $sql = "UPDATE angsuran
                  SET potongan = ?, sisa = ?, tanggal = NOW()
                  WHERE id = ?";
          $stmt = $conn->prepare($sql);
          $stmt->bind_param("ddi", $potongan, $sisa_baru, $id);
        } else {
          $sql = "UPDATE angsuran
                  SET sisa = ?, tanggal = NOW()
                  WHERE id = ?";
          $stmt = $conn->prepare($sql);
          $stmt->bind_param("di", $sisa_baru, $id);
        }
        $stmt->execute();
        $stmt->close();

        // Jika lunas, tandai status 'lunas'
        if ($sisa_baru == 0) {
          $conn->query("UPDATE angsuran SET status = 'lunas' WHERE id = {$id}");
        }

        jecho([
          "success" => true,
          "message" => "Potongan berhasil diperbarui",
          "data"    => ["sisa_baru" => $sisa_baru]
        ]);
      }

      // --- Update status ---
      if (isset($input['status'])) {
        $status = (string)$input['status']; // 'pending' | 'disetujui' | 'ditolak' | 'lunas'
        $sql = "UPDATE angsuran SET status = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $status, $id);

        if ($stmt->execute()) {
          jecho(["success" => true, "message" => "Status berhasil diperbarui"]);
        } else {
          jecho(["success" => false, "message" => "Gagal memperbarui status"], 500);
        }
      }

      jecho(["success" => false, "message" => "Tidak ada data untuk diupdate"], 400);
    }

    /* =======================
     * DELETE
     * ======================= */
   // 🔹 DELETE (hanya boleh jika status = 'ditolak' ATAU sisa <= 0)
case 'DELETE':
    parse_str(file_get_contents("php://input"), $_DELETE);
    if (!isset($_DELETE['id'])) {
        echo json_encode(["success" => false, "message" => "ID tidak ditemukan"]);
        exit;
    }
    $id = (int)$_DELETE['id'];

    // Cek status & sisa
    $cek = $conn->prepare("SELECT status, COALESCE(sisa, nominal) AS sisa FROM angsuran WHERE id = ?");
    $cek->bind_param("i", $id);
    $cek->execute();
    $row = $cek->get_result()->fetch_assoc();
    $cek->close();

    if (!$row) {
        echo json_encode(["success" => false, "message" => "Data tidak ditemukan"]);
        exit;
    }

    $status = strtolower((string)($row['status'] ?? ''));
    $sisa   = (float)$row['sisa'];

    if (!($status === 'ditolak' || $sisa <= 0)) {
        echo json_encode([
          "success" => false,
          "message" => "Tidak dapat menghapus: angsuran masih berjalan / belum lunas."
        ]);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM angsuran WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Data berhasil dihapus"]);
    } else {
        echo json_encode(["success" => false, "message" => "Gagal menghapus data"]);
    }
    break;

    default:
      jecho(["success" => false, "message" => "Metode tidak diizinkan"], 405);
  }
} catch (Throwable $e) {
  jecho(["success" => false, "message" => "Server error: ".$e->getMessage()], 500);
} finally {
  if (isset($conn) && $conn instanceof mysqli) $conn->close();
}