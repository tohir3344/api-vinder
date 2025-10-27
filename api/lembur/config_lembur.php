<?php
declare(strict_types=1);

// PUSATNYA di sini:
const LE_START_CUTOFF = '15:00';   // sebelum ini = lembur masuk
const LE_END_CUTOFF   = '15:10';   // setelah ini = lembur keluar

// opsional (kalau pakai upah):
const LE_RATE_PER_JAM   = 10000;
const LE_RATE_PER_MENIT = LE_RATE_PER_JAM / 60;
