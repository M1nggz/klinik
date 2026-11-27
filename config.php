<?php
// ================= TETAPAN UTAMA =================
date_default_timezone_set('Asia/Kuala_Lumpur');

// --- Database (XAMPP lalai). Di InfinityFree: isi dari panel MySQL Databases ---
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'klinik_sihat');

// --- Kunci penyulitan App Password (sama seperti dalam zip PHPMailer anda) ---
define('ENC_KEY', 'ee010c5008f55d4e6679d536183e9622fcb5684ca792edac9782ab9c9a770750');

// --- URL laman ---
// XAMPP : BASE_PATH '/klinik'  | InfinityFree : BASE_PATH '' dan BASE_URL 'https://nama-anda.infinityfreeapp.com'
define('BASE_PATH', '/klinik');
define('BASE_URL',  'http://localhost' . BASE_PATH);

define('SITE_NAME', 'CompSciCorp');

// --- AI Assistant (Python + ngrok) ---
// Selepas jalankan ai/ai_server.py dan `ngrok http 5000`, tampal URL https ngrok di sini
define('AI_URL', 'https://vastly-bankable-jumble.ngrok-free.dev');                       // contoh: https://abcd-1234.ngrok-free.app
define('AI_KEY', 'tukar-kunci-rahsia-ai');  // mesti sama dengan AI_KEY dalam ai_server.py

// --- Peringatan automatik appointment ---
define('REMIND_BEFORE_MIN', 60);  // emel peringatan X minit sebelum appointment
define('LATE_AFTER_MIN', 10);     // emel "anda lewat" selepas X minit dari masa appointment
define('NOSHOW_AFTER_MIN', 30);   // slot dilepaskan (missed) selepas X minit
define('CRON_KEY', 'tukar-kunci-cron');  // untuk cron.php?key=...
