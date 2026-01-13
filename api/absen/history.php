<?php
// api/absen/history.php

// --- HEADER WAJIB ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

// Handle Preflight Request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Matikan error visual biar JSON valid
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../Koneksi.php';

try {
    // =======================================================================
    // 1. HANDLE POST (UNTUK DELETE DATA)
    // =======================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $inputRaw = file_get_contents('php://input');
        $input    = json_decode($inputRaw, true);
        
        $action = $input['action'] ?? '';
        $id     = (int)($input['id'] ?? 0);

        // --- AKSI DELETE ---
        if ($action === 'delete') {
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
                exit;
            }

            $stmt = $conn->prepare("DELETE FROM absen WHERE id = ?");
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Data berhasil dihapus']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal DB: ' . $stmt->error]);
            }
            exit;
        }
        
        // (Bisa tambah aksi edit/update di sini kalau mau via POST)
        exit;
    }

    // =======================================================================
    // 2. HANDLE GET (UNTUK BACA/LIST DATA)
    // =======================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        
        // Ambil Parameter
        $user_id = (int)($_GET['user_id'] ?? 0);
        $limit   = (int)($_GET['limit'] ?? 20); // Default 20 biar ringan
        $start   = $_GET['start'] ?? null;
        $end     = $_GET['end'] ?? null;
        $q       = isset($_GET['q']) ? trim($_GET['q']) : ''; // Pencarian Nama

        if ($limit < 1) $limit = 7;

        // --- BUILD QUERY ---
        $sql = "SELECT 
                    a.id,
                    a.user_id,
                    a.tanggal, 
                    COALESCE(a.jam_masuk, '') AS jam_masuk, 
                    COALESCE(a.jam_keluar, '') AS jam_keluar,
                    COALESCE(a.status, 'HADIR') AS keterangan,  
                    COALESCE(a.alasan, '') AS alasan,
                    COALESCE(a.foto_masuk, '') AS foto_masuk,
                    COALESCE(a.foto_keluar, '') AS foto_keluar,
                    u.nama_lengkap, 
                    u.username
                FROM absen a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE 1=1 ";

        $params = [];
        $types = ""; 

        // 1. FILTER USER ID
        if ($user_id > 0) {
            $sql .= " AND a.user_id = ? ";
            $params[] = $user_id;
            $types .= "i"; 
        }

        // 2. FILTER TANGGAL
        if (!empty($start) && !empty($end)) {
            $sql .= " AND a.tanggal BETWEEN ? AND ? ";
            $params[] = $start;
            $params[] = $end;
            $types .= "ss"; 
        }

        // 3. FILTER PENCARIAN NAMA (Untuk Admin)
        if (!empty($q)) {
            $sql .= " AND (u.nama_lengkap LIKE ? OR u.username LIKE ?) ";
            $searchParam = "%$q%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "ss";
        }

        // 4. LIMIT & URUTAN
        $sql .= " ORDER BY a.tanggal DESC, a.jam_masuk DESC LIMIT ?";
        $params[] = $limit;
        $types .= "i"; 

        // Eksekusi
        if ($stmt = $conn->prepare($sql)) {
            if (!empty($types)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $res = $stmt->get_result();

            $out = [];
            while ($r = $res->fetch_assoc()) { 
                $jm = substr($r['jam_masuk'], 0, 5) ?: '';
                $jk = substr($r['jam_keluar'], 0, 5) ?: '';
                
                $nama = !empty($r['nama_lengkap']) ? $r['nama_lengkap'] : (!empty($r['username']) ? $r['username'] : 'Karyawan');

                $out[] = [
                    'id'          => (int)$r['id'],      // Penting buat Delete/Edit
                    'user_id'     => (int)$r['user_id'], // Penting buat identifikasi
                    'nama'        => $nama,
                    'tanggal'     => $r['tanggal'],
                    'jam_masuk'   => $jm,
                    'jam_keluar'  => $jk,
                    'keterangan'  => $r['keterangan'],
                    'alasan'      => $r['alasan'],
                    'foto_masuk'  => $r['foto_masuk'],
                    'foto_keluar' => $r['foto_keluar'],
                ];
            }
            echo json_encode(['success'=>true, 'data'=>$out]);
        } else {
            throw new Exception("Gagal Prepare SQL: " . $conn->error);
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
?>