<?php
// api/attendance/admin_upsert.php
declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../Koneksi.php'; 
require_once __DIR__ . '/../lembur/config_lembur.php'; 

try {
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
  }

  $raw = file_get_contents("php://input");
  $json = json_decode($raw, true);
  
  // --- Mapping Input ---
  $mode       = isset($json['mode']) ? strtolower(trim((string)$json['mode'])) : 'create';
  $id         = isset($json['id']) ? (int)$json['id'] : null;
  $user_id    = isset($json['user_id']) ? (int)$json['user_id'] : 0;
  $tanggal    = isset($json['tanggal']) ? trim((string)$json['tanggal']) : '';
  $jam_masuk  = !empty($json['jam_masuk']) ? $json['jam_masuk'] : null;
  $jam_keluar = !empty($json['jam_keluar']) ? $json['jam_keluar'] : null;
  $status     = isset($json['status']) ? strtoupper(trim((string)$json['status'])) : 'HADIR';
  
  // 🔥 TANGKAP DUA ALASAN DARI FRONTEND 🔥
  // 'alasan' dari frontend itu isinya alasan_masuk
  $input_alasan_masuk  = !empty($json['alasan']) ? $json['alasan'] : null;
  $input_alasan_keluar = !empty($json['alasan_keluar']) ? $json['alasan_keluar'] : null;

  if ($user_id <= 0 || $tanggal === '') throw new Exception("User ID & Tanggal wajib diisi");

  // ========================================================
  // PART 1: LOGIC ABSEN (Tabel 'absen')
  // ========================================================
  
  // Aturan User: "Di halaman absensi gausah tampil alasan lembur"
  // Jadi: Kalau status HADIR, kita kosongin alasan di tabel absen.
  // Kalau status IZIN/SAKIT/ALPHA, baru kita pake alasannya.
  
  $alasan_untuk_absen = null;
  if ($status !== 'HADIR') {
      $alasan_untuk_absen = $input_alasan_masuk; 
  }

  if ($mode === 'update') {
    if (!$id) throw new Exception("ID wajib untuk update");
    $stmt = $conn->prepare("UPDATE absen SET user_id=?, tanggal=?, jam_masuk=?, jam_keluar=?, status=?, alasan=? WHERE id=?");
    // Perhatikan: parameter terakhir alasan_untuk_absen
    $stmt->bind_param("isssssi", $user_id, $tanggal, $jam_masuk, $jam_keluar, $status, $alasan_untuk_absen, $id);
    $stmt->execute();
  } else {
    $cek = $conn->prepare("SELECT id FROM absen WHERE user_id=? AND tanggal=?");
    $cek->bind_param("is", $user_id, $tanggal);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) throw new Exception("User sudah absen di tanggal ini.");
    
    $stmt = $conn->prepare("INSERT INTO absen (user_id, tanggal, jam_masuk, jam_keluar, status, alasan) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $user_id, $tanggal, $jam_masuk, $jam_keluar, $status, $alasan_untuk_absen);
    $stmt->execute();
  }

  // ========================================================
  // PART 2: LOGIC LEMBUR (Tabel 'lembur')
  // ========================================================
  
  // Kalau status HADIR, baru kita urus lemburnya
  // Atau kalau lu mau tetep catet lembur meski statusnya 'DINAS LUAR' bisa disesuaikan.
  // Default: Kita hitung aja.

  $rate_per_min = LE_RATE_PER_MENIT; 

  // 1. HITUNG LEMBUR MASUK
  $total_menit_masuk = 0;
  if ($jam_masuk) {
    $ts_masuk  = strtotime("$tanggal $jam_masuk");
    $ts_limit  = strtotime("$tanggal " . LE_START_CUTOFF); // 07:30
    $ts_base   = strtotime("$tanggal " . OFFICE_START_TIME); // 08:00

    if ($ts_masuk < $ts_limit) {
        $total_menit_masuk = round(($ts_base - $ts_masuk) / 60);
    }
  }

  // 2. HITUNG LEMBUR KELUAR
  $total_menit_keluar = 0;
  if ($jam_keluar) {
    $ts_keluar = strtotime("$tanggal $jam_keluar");
    $ts_limit  = strtotime("$tanggal " . LE_END_CUTOFF); // 17:30
    $ts_base   = strtotime("$tanggal " . OFFICE_END_TIME); // 17:00

    if ($ts_keluar > $ts_limit) {
        $total_menit_keluar = round(($ts_keluar - $ts_base) / 60);
    }
  }

  $total_menit = $total_menit_masuk + $total_menit_keluar;
  $total_jam   = $total_menit / 60;
  $total_upah  = $total_menit * $rate_per_min;

  // --- PART 3: SIMPAN KE TABEL LEMBUR (FULL DATA) ---
  // Di sini kita simpan input_alasan_masuk & input_alasan_keluar
  
  $cekL = $conn->prepare("SELECT id FROM lembur WHERE user_id=? AND tanggal=?");
  $cekL->bind_param("is", $user_id, $tanggal);
  $cekL->execute();
  $resL = $cekL->get_result();

  if ($rowL = $resL->fetch_assoc()) {
      // UPDATE
      $lembur_id = $rowL['id'];
      
      // Pastikan kolom 'alasan_keluar' ada di database lu ya!
      $upd = $conn->prepare("UPDATE lembur SET 
          jam_masuk=?, jam_keluar=?, 
          alasan=?,        -- Alasan Masuk
          alasan_keluar=?, -- Alasan Keluar (NEW)
          total_menit_masuk=?, total_menit_keluar=?, 
          total_menit=?, total_upah=?, total_jam=?
          WHERE id=?");
      
      $upd->bind_param("ssssiiiddi", 
          $jam_masuk, $jam_keluar, 
          $input_alasan_masuk, $input_alasan_keluar, 
          $total_menit_masuk, $total_menit_keluar, 
          $total_menit, $total_upah, $total_jam, 
          $lembur_id
      );
      $upd->execute();
  } else {
      // INSERT
      $ins = $conn->prepare("INSERT INTO lembur 
          (user_id, tanggal, jam_masuk, jam_keluar, alasan, alasan_keluar, total_menit_masuk, total_menit_keluar, total_menit, total_upah, total_jam) 
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
      
      $ins->bind_param("isssssiiidd", 
          $user_id, $tanggal, $jam_masuk, $jam_keluar, 
          $input_alasan_masuk, $input_alasan_keluar,
          $total_menit_masuk, $total_menit_keluar, 
          $total_menit, $total_upah, $total_jam
      );
      $ins->execute();
  }

  echo json_encode([
      "success" => true, 
      "message" => "Data tersimpan! Absen bersih, Lembur lengkap.",
      "debug" => [
         "alasan_absen" => $alasan_untuk_absen,
         "alasan_lembur_masuk" => $input_alasan_masuk,
         "alasan_lembur_keluar" => $input_alasan_keluar
      ]
  ]);

} catch (Throwable $e) {
  echo json_encode(["success"=>false, "message"=>$e->getMessage()]);
}
?>