<?php
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
  session_start();
}

// ---------- Database (auto-cipta DB & jadual pada larian pertama) ----------
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
  $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
  try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opt);
  } catch (PDOException $e) {
    if ((int)$e->getCode() !== 1049) throw $e;   // 1049 = database belum wujud (XAMPP)
    $tmp = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, $opt);
    $tmp->exec('CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4');
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opt);
  }
  try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Throwable $e) {}
  if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) install_schema($pdo);
  elseif (!$pdo->query("SELECT 1 FROM users WHERE role='admin' LIMIT 1")->fetch()) seed_defaults($pdo);  // import manual: seed admin & doktor contoh
  if (empty($_SESSION['schema_v2'])) {   // migrasi untuk database sedia ada: lajur follow-up
    if (!$pdo->query("SHOW COLUMNS FROM appointments LIKE 'followup_of'")->fetch()) $pdo->exec('ALTER TABLE appointments ADD COLUMN followup_of INT DEFAULT NULL');
    $_SESSION['schema_v2'] = 1;
  }
  if (empty($_SESSION['schema_v3'])) {   // migrasi: penanda notifikasi (titik merah)
    if (!$pdo->query("SHOW COLUMNS FROM appointments LIKE 'patient_seen'")->fetch()) $pdo->exec('ALTER TABLE appointments ADD COLUMN patient_seen TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN doctor_seen TINYINT(1) NOT NULL DEFAULT 1');
    if (!$pdo->query("SHOW COLUMNS FROM medical_records LIKE 'patient_seen'")->fetch()) $pdo->exec('ALTER TABLE medical_records ADD COLUMN patient_seen TINYINT(1) NOT NULL DEFAULT 1');
    $_SESSION['schema_v3'] = 1;
  }
  if (empty($_SESSION['schema_v4'])) {   // migrasi: pengetahuan AI
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_knowledge (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(120) NOT NULL, questions TEXT NOT NULL, answer TEXT NOT NULL, audience ENUM('all','patient','staff') NOT NULL DEFAULT 'all', active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_unanswered (id INT AUTO_INCREMENT PRIMARY KEY, message VARCHAR(300) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $_SESSION['schema_v4'] = 1;
  }
  return $pdo;
}

function qv(string $sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function unread_count(?int $uid): int { return $uid ? (int)qv('SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0', [$uid]) : 0; }

// Penanda "sudah dilihat" untuk admin (disimpan dalam jadual settings)
function seen_get(string $k): int { return (int)qv('SELECT v FROM settings WHERE k=?', [$k]); }
function admin_seen_mark(string $what): void {
  $q = ['appt' => 'SELECT COALESCE(MAX(id),0) FROM appointments', 'patient' => "SELECT COALESCE(MAX(id),0) FROM users WHERE role='patient'",
        'msg' => 'SELECT COALESCE(MAX(id),0) FROM messages', 'fail' => "SELECT COALESCE(MAX(id),0) FROM activity_log WHERE action='email_failed'"][$what];
  db()->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)')->execute(['seen_' . (int)($_SESSION['uid'] ?? 0) . '_' . $what, (int)qv($q)]);
}

function install_schema(PDO $pdo): void {
  $sql = file_get_contents(__DIR__ . '/../database.sql');
  foreach (preg_split('/;\s*\n/', $sql) as $q) { if (trim($q) !== '') $pdo->exec($q); }
  seed_defaults($pdo);
}

function seed_defaults(PDO $pdo): void {
  // Data contoh (tukar emel & kata laluan selepas login pertama)
  $ins = $pdo->prepare('INSERT INTO users (role, matric_no, username, email, password_hash, specialty, bio) VALUES (?,?,?,?,?,?,?)');
  $ins->execute(['admin', 'ADMIN001', 'System Admin', 'admin@example.com', password_hash('Admin@123', PASSWORD_DEFAULT), null, null]);
  $docs = [
    ['DR001', 'Dr. Aisha Rahman', 'dr001@example.com', 'General Practitioner', 'Primary care for everyday illness, fever, check-ups and referrals.'],
    ['DR002', 'Dr. Daniel Lim', 'dr002@example.com', 'Dentist', 'Dental check-ups, scaling, fillings and oral health advice.'],
    ['DR003', 'Dr. Nur Aisyah', 'dr003@example.com', 'Mental Health Counsellor', 'Stress, anxiety, study-life balance and confidential counselling.'],
  ];
  $sch = $pdo->prepare('INSERT INTO doctor_schedules (doctor_id, weekday, start_time, end_time, slot_minutes) VALUES (?,?,?,?,30)');
  foreach ($docs as $d) {
    $ins->execute(['doctor', $d[0], $d[1], $d[2], password_hash('Doctor@123', PASSWORD_DEFAULT), $d[3], $d[4]]);
    $id = (int)$pdo->lastInsertId();
    for ($w = 1; $w <= 5; $w++) { $sch->execute([$id, $w, '09:00', '12:00']); $sch->execute([$id, $w, '14:00', '17:00']); }
  }
}

// ---------- Utiliti ----------
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function url(string $p = ''): string { return BASE_PATH . '/' . ltrim($p, '/'); }
function redirect(string $p): void { header('Location: ' . (str_starts_with($p, 'http') || str_starts_with($p, '/') ? $p : url($p))); exit; }
function fmt_date(string $d): string { return date('D, d M Y', strtotime($d)); }
function fmt_time(string $t): string { return date('g:i A', strtotime($t)); }
function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'cli'; }

function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function csrf_check(): void {
  $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
  if (!hash_equals(csrf(), (string)$t)) { http_response_code(419); exit('Session expired. Please go back and refresh the page.'); }
}

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function log_act(string $action, string $details = '', ?int $uid = null): void {
  try {
    $uid ??= $_SESSION['uid'] ?? null;
    db()->prepare('INSERT INTO activity_log (user_id, action, details, ip) VALUES (?,?,?,?)')
        ->execute([$uid, $action, mb_substr($details, 0, 500), ip()]);
  } catch (Throwable $e) {}
}

// ---------- Auth ----------
function home_for(string $role): string { return url($role . '/dashboard.php'); }

function me(): ?array {
  static $u = false;
  if ($u !== false) return $u;
  if (empty($_SESSION['uid']) && !empty($_COOKIE['rm'])) remember_login();
  if (empty($_SESSION['uid'])) return $u = null;
  $st = db()->prepare("SELECT * FROM users WHERE id=? AND status='active'");
  $st->execute([$_SESSION['uid']]);
  $u = $st->fetch() ?: null;
  if (!$u) unset($_SESSION['uid']);
  return $u;
}

function require_login(?string $role = null): array {
  $u = me();
  if (!$u) { flash('err', 'Please log in first.'); redirect('login.php'); }
  if ($role && $u['role'] !== $role) redirect(home_for($u['role']));
  return $u;
}

function set_remember_cookie(int $uid): void {
  $sel = bin2hex(random_bytes(9)); $val = bin2hex(random_bytes(32));
  db()->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 30 DAY))')
      ->execute([$uid, $sel, hash('sha256', $val)]);
  setcookie('rm', "$sel:$val", ['expires' => time() + 86400 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

function remember_login(): void {
  [$sel, $val] = array_pad(explode(':', (string)$_COOKIE['rm'], 2), 2, '');
  $st = db()->prepare('SELECT * FROM remember_tokens WHERE selector=? AND expires_at>NOW()');
  $st->execute([$sel]);
  $row = $st->fetch();
  if ($row && hash_equals($row['validator_hash'], hash('sha256', $val))) {
    db()->prepare('DELETE FROM remember_tokens WHERE id=?')->execute([$row['id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['user_id'];
    set_remember_cookie((int)$row['user_id']);
  } else {
    setcookie('rm', '', time() - 3600, '/');
  }
}

function login_user(array $u, bool $remember): void {
  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$u['id'];
  if ($remember) set_remember_cookie((int)$u['id']);
  log_act('login', $u['role'] . ' ' . $u['matric_no'], (int)$u['id']);
}

function logout_user(): void {
  if (!empty($_COOKIE['rm'])) {
    [$sel] = explode(':', $_COOKIE['rm'], 2);
    db()->prepare('DELETE FROM remember_tokens WHERE selector=?')->execute([$sel]);
    setcookie('rm', '', time() - 3600, '/');
  }
  log_act('logout');
  $_SESSION = []; session_destroy();
}

// ---------- Kandungan kesihatan (diilhamkan oleh ciri apl MySejahtera) ----------
function health_tips(): array {
  return [
    ['💧', 'Stay hydrated', 'Aim for 8 glasses of plain water a day, especially in hot weather and during exams.'],
    ['🥗', 'Suku Suku Separuh', 'Fill a quarter of your plate with carbs, a quarter with protein and half with fruit and vegetables.'],
    ['🏃', 'Move every day', 'Adults should get at least 150 minutes of moderate physical activity each week.'],
    ['😴', 'Sleep well', 'Most adults need 7–9 hours of sleep. A steady routine improves focus and mood.'],
    ['💉', 'Keep vaccinations current', 'Ask your doctor about flu, HPV and other recommended immunisations for students.'],
    ['🧠', 'Look after your mind', 'Feeling overwhelmed? Talk to a counsellor — it is confidential and it helps.'],
  ];
}


// ---------- Pengetahuan AI (dikemas kini langsung oleh admin) ----------
function ai_knowledge(string $aud): array {
  $s = db()->prepare("SELECT id, questions q FROM ai_knowledge WHERE active=1 AND audience IN ('all', ?) ORDER BY id DESC LIMIT 100");
  $s->execute([$aud]); return $s->fetchAll();
}
function kb_answer(int $id, string $aud): ?string {
  $s = db()->prepare("SELECT answer FROM ai_knowledge WHERE id=? AND active=1 AND audience IN ('all', ?)");
  $s->execute([$id, $aud]); $a = $s->fetchColumn(); return $a === false ? null : (string)$a;
}
// Waktu operasi sebenar daripada jadual semua doktor aktif (sentiasa terkini)
function clinic_hours(): string {
  $r = db()->query("SELECT s.weekday, MIN(s.start_time) a, MAX(s.end_time) b FROM doctor_schedules s JOIN users u ON u.id=s.doctor_id AND u.status='active' GROUP BY s.weekday ORDER BY s.weekday")->fetchAll();
  if (!$r) return 'No doctor has published availability yet, so there are no bookable hours at the moment.';
  $d = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
  return "Doctor availability by day (earliest start – latest finish; each doctor sets their own hours):\n" . implode("\n", array_map(fn($x) => '• ' . $d[$x['weekday']] . ': ' . fmt_time($x['a']) . ' – ' . fmt_time($x['b']), $r)) . "\nPick a doctor and date on the booking page for exact free times.";
}
