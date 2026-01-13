<?php
// api/izin/izin_set_status.php
declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';

try {
  $raw = file_get_contents('php://input');
  $in  = json_decode($raw, true) ?: [];

  $id     = isset($in['id']) ? (int)$in['id'] : 0;
  $status = isset($in['status']) ? trim((string)$in['status']) : '';

  if ($id <= 0) throw new Exception("id invalid");
  
  // Normalisasi status biar aman (lowercase)
  $status = strtolower($status);
  $allowed = ['pending','disetujui','ditolak','approved','rejected'];
  if (!in_array($status, $allowed, true)) throw new Exception("status invalid");

  // 1. Update status di tabel IZIN
  $stmt = $conn->prepare("UPDATE izin SET status = ? WHERE id = ?");
  $stmt->bind_param("si", $status, $id);
  if (!$stmt->execute()) throw new Exception("Gagal update status: " . $stmt->error);

  // ==================================================================
  // 🔥 FITUR BARU: AUTO-INJECT KE TABEL ABSEN JIKA DISETUJUI 🔥
  // ==================================================================
  if ($status === 'disetujui' || $status === 'approved') {
      
      // A. Ambil detail data izinnya dulu
      $qGet = $conn->query("SELECT * FROM izin WHERE id = $id LIMIT 1");
      if ($qGet && $qGet->num_rows > 0) {
          $dIzin = $qGet->fetch_assoc();
          
          $userId = $dIzin['user_id'];
          
          // Ambil jenis izin (IZIN/SAKIT)
          $jenisIzin = isset($dIzin['keterangan']) ? strtoupper($dIzin['keterangan']) : 'IZIN'; 
          
          // Ambil alasan dari tabel izin
          $alasanText = isset($dIzin['alasan']) ? $dIzin['alasan'] : '-';

          // Normalisasi tanggal
          $tglMulai   = $dIzin['mulai'] ?? $dIzin['tanggal_mulai'];
          $tglSelesai = $dIzin['selesai'] ?? $dIzin['tanggal_selesai'];

          // B. Loop dari Tanggal Mulai s/d Selesai
          $start = new DateTime($tglMulai);
          $end   = new DateTime($tglSelesai);
          $end->modify('+1 day'); 
          
          $period = new DatePeriod($start, new DateInterval('P1D'), $end);

          foreach ($period as $dt) {
              $currentDate = $dt->format('Y-m-d');
              
              // C. Masukkan ke tabel ABSEN (Pake kolom 'alasan')
              // Logic: 
              // - Status jadi "IZIN" atau "SAKIT"
              // - Kolom 'alasan' diisi alasan user
              // - Jam Masuk & Keluar di-NULL-kan biar gak dihitung telat
              
              $stmtAbs = $conn->prepare("
                  INSERT INTO absen (user_id, tanggal, status, alasan, jam_masuk, jam_keluar)
                  VALUES (?, ?, ?, ?, NULL, NULL)
                  ON DUPLICATE KEY UPDATE 
                      status = VALUES(status),
                      alasan = VALUES(alasan),
                      jam_masuk = NULL, 
                      jam_keluar = NULL
              ");
              
              if ($stmtAbs) {
                  $stmtAbs->bind_param('isss', $userId, $currentDate, $jenisIzin, $alasanText);
                  $stmtAbs->execute();
                  $stmtAbs->close();
              }
          }
      }
  }
  // ==================================================================

  echo json_encode(['success' => true, 'id' => $id, 'status' => $status], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>