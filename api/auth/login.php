<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../Koneksi.php';

function out($x,$c=200){ http_response_code($c); echo json_encode($x, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['success'=>false,'message'=>'Use POST'],405);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$identity = trim((string)($body['username'] ?? ''));  // bisa username ATAU email
$password = (string)($body['password'] ?? '');
if ($identity==='' || $password==='') out(['success'=>false,'message'=>'Username/email dan password wajib diisi'],400);

$stmt = $conn->prepare("SELECT id, username, email, password, role FROM users WHERE username=? OR email=? LIMIT 1");
$stmt->bind_param("ss", $identity, $identity);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user || $password !== $user['password']) out(['success'=>false,'message'=>'Akun atau password salah'],401);

out([
  'success'=>true,
  'message'=>'Login berhasil',
  'data'=>[
    'id'       => (int)$user['id'],
    'username' => $user['username'],
    'email'    => $user['email'],
    'role'     => $user['role']
  ]
]);
