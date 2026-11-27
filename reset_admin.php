<?php
// ALAT PEMBAIKAN: cipta / set semula admin kepada ADMIN001 + Admin@123.
// Hanya boleh dijalankan dari komputer sendiri (localhost). PADAM fail ini selepas guna.
require __DIR__ . '/includes/core.php';
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('Forbidden: localhost only.'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$hash = password_hash('Admin@123', PASSWORD_DEFAULT);
$st = $pdo->prepare("SELECT id FROM users WHERE matric_no='ADMIN001'"); $st->execute();
if ($id = $st->fetchColumn()) {
  $pdo->prepare("UPDATE users SET role='admin', status='active', password_hash=? WHERE id=?")->execute([$hash, $id]);
  echo "Admin sedia ada DISET SEMULA.\n";
} else {
  $pdo->prepare("INSERT INTO users (role, matric_no, username, email, password_hash) VALUES ('admin','ADMIN001','System Admin','admin@example.com',?)")->execute([$hash]);
  echo "Admin BARU dicipta.\n";
}
echo "Login: ADMIN001 / Admin@123\n";
echo "Jumlah pengguna dalam jadual users: " . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . "\n";
echo "Sekarang PADAM fail reset_admin.php dari folder klinik.\n";
