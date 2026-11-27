<?php
// Jalankan: php cron.php  (Task Scheduler / cron setiap 5 minit)  ATAU  https://laman-anda/cron.php?key=CRON_KEY (cron-job.org)
require __DIR__ . '/includes/boot.php';
if (PHP_SAPI !== 'cli' && !hash_equals(CRON_KEY, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('Forbidden'); }
$r = run_reminders();
header('Content-Type: application/json'); echo json_encode($r);
