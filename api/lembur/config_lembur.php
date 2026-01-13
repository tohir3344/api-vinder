<?php
declare(strict_types=1);

// ===========================================
//   KONFIGURASI JAM KERJA & LEMBUR (FIXED)
// ===========================================

// 1. JAM KANTOR RESMI (Jam 8 Pagi - Jam 5 Sore)
if (!defined('OFFICE_START_TIME')) {
    define('OFFICE_START_TIME', '08:00:00'); 
}
if (!defined('OFFICE_END_TIME')) {
    define('OFFICE_END_TIME', '17:00:00');   
}

// 2. TOLERANSI / BUFFER (Dalam Menit)
// Ini yang bikin "Setengah 8" dan "Setengah 6" jadi batasnya.
if (!defined('OVERTIME_BEFORE_MIN')) {
    define('OVERTIME_BEFORE_MIN', 30); // 08:00 - 30 menit = 07:30
}
if (!defined('OVERTIME_AFTER_MIN')) {
    define('OVERTIME_AFTER_MIN', 30);  // 17:00 + 30 menit = 17:30
}

// 3. LOGIC CUTOFF (Otomatis Hitung)
// Jangan ubah manual angka di bawah ini, biarkan PHP yang hitung.

// Batas Awal Lembur: 07:30:00
if (!defined('LE_START_CUTOFF')) {
    $ts = strtotime(OFFICE_START_TIME) - (OVERTIME_BEFORE_MIN * 60);
    define('LE_START_CUTOFF', date('H:i:s', $ts)); 
}

// Batas Akhir Lembur: 17:30:00
if (!defined('LE_END_CUTOFF')) {
    $ts = strtotime(OFFICE_END_TIME) + (OVERTIME_AFTER_MIN * 60);
    define('LE_END_CUTOFF', date('H:i:s', $ts));
}

// Tarif lembur (default)
if (!defined('LE_RATE_PER_JAM')) {
    define('LE_RATE_PER_JAM', 10000);
}
if (!defined('LE_RATE_PER_MENIT')) {
    define('LE_RATE_PER_MENIT', LE_RATE_PER_JAM / 60);
}

// Kedisiplinan
if (!defined('DISCIPLINE_ON_TIME_MAX')) {
    define('DISCIPLINE_ON_TIME_MAX', '07:45:00');
}
if (!defined('DISCIPLINE_HANGUS_AT')) {
    define('DISCIPLINE_HANGUS_AT', '08:00:00');
}

// Helper Function (Gak usah diubah)
if (!function_exists('is_lembur_time')) {
    function is_lembur_time(?string $jam): bool {
        if ($jam === null || $jam === '') return false;
        
        $base = date('Y-m-d');
        $tsJam = strtotime("$base $jam");
        
        // Ambil batas yang udah dihitung di atas
        $tsStartCutoff = strtotime("$base " . LE_START_CUTOFF); // 07:30
        $tsEndCutoff   = strtotime("$base " . LE_END_CUTOFF);   // 17:30

        if ($tsJam < $tsStartCutoff) return true; // Sebelum 07:30 -> LEMBUR
        if ($tsJam > $tsEndCutoff)   return true; // Setelah 17:30 -> LEMBUR

        return false; // Di antara 07:30 s.d 17:30 -> BUKAN LEMBUR
    }
}
?>