<?php
// api/lembur/lembur_list.php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../Koneksi.php';

function jamKeMenit($str) {
    if (!$str) return 0;
    $j = substr(str_replace('.', ':', $str), 0, 5);
    $p = explode(':', $j);
    return ((int)$p[0] * 60) + (int)($p[1]??0);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $act    = $_GET['action'] ?? ($_POST['action'] ?? 'list');

    // ==========================================================
    // 1. GET: LIST DATA
    // ==========================================================
    if ($method === 'GET') {
        if ($act === 'config') {
            // Default global (fallback jika user rate 0)
            echo json_encode(["start_cutoff" => "08:00", "end_cutoff" => "17:00", "rate_per_menit" => 166]); exit;
        }
        
        if ($act === 'list') {
            $filter_uid = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
            
            // Filter hanya yang ada durasinya
            $syarat = ["l.total_menit > 0"];
            if ($filter_uid > 0) {
                $syarat[] = "l.user_id = $filter_uid";
            }
            $sql_where = "WHERE " . implode(" AND ", $syarat);
            
            // 🔥 UPDATE QUERY: Ambil u.lembur sebagai 'upah_db'
            $sql = "SELECT 
                        l.*, 
                        COALESCE(u.nama_lengkap, u.username) as nama, 
                        u.lembur as upah_db 
                    FROM lembur l 
                    LEFT JOIN users u ON u.id = l.user_id 
                    $sql_where 
                    ORDER BY l.tanggal DESC LIMIT 200";
            
            $res = $conn->query($sql);
            $rows = [];
            
            while ($r = $res->fetch_assoc()) {
                // Pastikan tipe data benar
                $r['total_upah']   = (int)($r['total_upah']);
                $r['total_menit']  = (int)($r['total_menit']);
                $r['jenis_lembur'] = !empty($r['jenis_lembur']) ? $r['jenis_lembur'] : 'biasa';
                
                // 🔥 LOGIC RATE PER USER 🔥
                // 1. Ambil dari DB user (upah_db)
                $ratePerJamUser = (int)($r['upah_db'] ?? 0);
                
                // 2. Jika user tidak punya seting upah (0), pakai default 10.000
                if ($ratePerJamUser <= 0) $ratePerJamUser = 10000;

                // 3. Masukkan ke array response dengan key yang konsisten
                $r['rate_per_jam'] = $ratePerJamUser;

                // 🔥 LOGIC FIX: Jika total_upah di DB 0 (data lama), hitung ulang
                if ($r['total_upah'] === 0 && $r['total_menit'] > 0) {
                      $pengali = ($r['jenis_lembur'] === 'over') ? 2 : 1;
                      // Rumus: (Menit / 60) * RateJam * Pengali
                      $r['total_upah'] = (int)ceil(((float)$r['total_menit'] / 60) * ((float)$ratePerJamUser * $pengali));
                }
                
                $rows[] = $r;
            }
            echo json_encode(['success' => true, 'data' => $rows]); exit;
        }
    }

    // ==========================================================
    // 2. POST: SIMPAN / EDIT / DELETE
    // ==========================================================
    if ($method === 'POST') {
        $input  = json_decode(file_get_contents('php://input'), true);
        $data   = $input['data'] ?? [];
        $action = $input['action'] ?? '';

        // --- A. DELETE ---
        if ($action === 'delete') {
            $id = (int)($input['id'] ?? ($data['id'] ?? 0));
            if ($id <= 0) throw new Exception("ID tidak valid");
            $conn->query("DELETE FROM lembur WHERE id = $id");
            echo json_encode(["success" => true, "message" => "Data dihapus"]); exit;
        }

        // --- B. CREATE / EDIT ---
        $user_id = (int)($data['user_id'] ?? 0);
        
        // Ambil data user untuk mendapatkan TARIF LEMBUR
        $qU = $conn->query("SELECT id, lembur FROM users WHERE id = $user_id LIMIT 1");
        $uData = $qU->fetch_assoc();
        
        // Fallback cari by nama jika ID kosong/salah
        if (!$uData) { 
             $nm = $conn->real_escape_string($data['nama']??'');
             $uData = $conn->query("SELECT id, lembur FROM users WHERE nama_lengkap = '$nm' LIMIT 1")->fetch_assoc();
        }
        if (!$uData) throw new Exception("User tidak ditemukan!");

        $real_user_id = $uData['id'];
        
        // 🔥 UPDATE: Tarif Dasar ambil dari kolom 'lembur'. Jika 0, default 10.000
        $tarif_user   = (float)$uData['lembur']; 
        $TARIF_DASAR  = ($tarif_user > 0) ? $tarif_user : 10000; 
        
        // Rate per menit = Tarif Jam / 60
        $RATE_PER_MENIT = $TARIF_DASAR / 60;

        // 1. Konversi Jam
        $mMasuk  = jamKeMenit($data['jam_masuk']);
        $mKeluar = jamKeMenit($data['jam_keluar']);
        if ($mKeluar < $mMasuk) $mKeluar += 1440; 

        // 2. Hitung Durasi (Potong jam kerja 08:00-17:00)
        $MENIT_08_00 = 8 * 60;  
        $MENIT_17_00 = 17 * 60; 

        $menit_lembur_pagi = 0;
        if ($mMasuk < $MENIT_08_00) {
            $batas_akhir_pagi = min($mKeluar, $MENIT_08_00);
            $menit_lembur_pagi = max(0, $batas_akhir_pagi - $mMasuk);
        }

        $menit_lembur_sore = 0;
        if ($mKeluar > $MENIT_17_00) {
            $batas_awal_sore = max($mMasuk, $MENIT_17_00);
            $menit_lembur_sore = max(0, $mKeluar - $batas_awal_sore);
        }

        $total_menit = $menit_lembur_pagi + $menit_lembur_sore;
        
        // Validasi Hapus jika durasi 0
        if ($total_menit <= 0) {
            if ($action === 'create') {
                echo json_encode(["success" => false, "message" => "Total lembur 0 menit (Masuk jam kerja normal)."]);
                exit;
            } elseif ($action === 'edit') {
                $id = (int)$data['id'];
                $conn->query("DELETE FROM lembur WHERE id = $id");
                echo json_encode(["success" => true, "message" => "Data dihapus karena durasi jadi 0."]);
                exit;
            }
        }

        $total_jam_desimal = round($total_menit / 60, 2);

        // 3. Hitung Total Upah
        $rawJenis = $data['jenis_lembur'] ?? $data['jenis'] ?? 'biasa';
        $jenis_lembur = strtolower($rawJenis); 

        // Kalau OVER, tarif dikali 2
        $multiplier = ($jenis_lembur === 'over') ? 2 : 1;
        
        // Rumus: Total Menit * (Rate Per Menit * Multiplier)
        $TOTAL_UPAH = (int)ceil($total_menit * ($RATE_PER_MENIT * $multiplier));

        // Params for SQL
        $tgl = $data['tanggal'];
        $jm  = $data['jam_masuk'];
        $jk  = $data['jam_keluar'];
        $al  = $data['alasan'] ?? '';
        $alk = $data['alasan_keluar'] ?? '';

        if ($action === 'create') {
            $stmt = $conn->prepare("INSERT INTO lembur (user_id, tanggal, jam_masuk, jam_keluar, alasan, alasan_keluar, jenis_lembur, total_menit, total_menit_masuk, total_menit_keluar, total_jam, total_upah) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("issssssiiidi", $real_user_id, $tgl, $jm, $jk, $al, $alk, $jenis_lembur, $total_menit, $menit_lembur_pagi, $menit_lembur_sore, $total_jam_desimal, $TOTAL_UPAH);
            $stmt->execute();
        } 
        else if ($action === 'edit') {
            $id = (int)$data['id'];
            $stmt = $conn->prepare("UPDATE lembur SET user_id=?, tanggal=?, jam_masuk=?, jam_keluar=?, alasan=?, alasan_keluar=?, jenis_lembur=?, total_menit=?, total_menit_masuk=?, total_menit_keluar=?, total_jam=?, total_upah=? WHERE id=?");
            $stmt->bind_param("issssssiiidii", $real_user_id, $tgl, $jm, $jk, $al, $alk, $jenis_lembur, $total_menit, $menit_lembur_pagi, $menit_lembur_sore, $total_jam_desimal, $TOTAL_UPAH, $id);
            $stmt->execute();
        }

        echo json_encode([
            "success" => true, 
            "message" => "Berhasil Simpan",
            "debug" => ["rate_dasar" => $TARIF_DASAR, "upah_final" => $TOTAL_UPAH]
        ]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>