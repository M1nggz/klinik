<?php
// Pembantu AI untuk DOKTOR & ADMIN. Semua data diambil mengikut peranan; tiada kata laluan, kunci,
// kandungan mesej peribadi, nombor telefon/IC atau diagnosis/nota klinikal didedahkan.
require __DIR__ . '/../includes/boot.php';
header('Content-Type: application/json');
$u = me();
if (!$u || !in_array($u['role'], ['doctor', 'admin'], true)) { http_response_code(401); exit(json_encode(['reply' => 'Please log in as staff to use the assistant.'])); }
csrf_check();
$msg = trim(mb_substr((json_decode(file_get_contents('php://input'), true)['message'] ?? ''), 0, 500));
if ($msg === '') exit(json_encode(['reply' => 'Please type a request.']));
$role = $u['role']; $id = (int)$u['id']; $pdo = db();

function out(string $reply, array $chips = []): void { exit(json_encode(['reply' => $reply, 'chips' => $chips], JSON_UNESCAPED_UNICODE)); }
function lk(string $label, string $path): array { return ['label' => $label, 'url' => url($path)]; }
function qk(string $label, ?string $send = null): array { return ['label' => $label, 'send' => $send ?? $label]; }
function bullets(array $l): string { return implode("\n", array_map(fn($x) => '• ' . $x, $l)); }
function ai_post(string $path, array $payload): ?array {
  if (!AI_URL) return null;
  $ch = curl_init(rtrim(AI_URL, '/') . $path);
  curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . AI_KEY, 'ngrok-skip-browser-warning: 1'], CURLOPT_POSTFIELDS => json_encode($payload)]);
  $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return ($r && $code === 200) ? json_decode($r, true) : null;
}
function name_tokens(string $s): array {
  static $stop = ['find', 'patient', 'pesakit', 'cari', 'look', 'last', 'when', 'did', 'see', 'next', 'visit', 'show', 'the', 'for', 'with', 'user', 'account', 'akaun', 'pengguna', 'doctor', 'doktor', 'matric', 'number', 'bila', 'saya', 'jumpa', 'kali', 'terakhir', 'lookup', 'who', 'is', 'tell', 'about'];
  return array_values(array_diff(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY), fn($t) => mb_strlen($t) >= 3), $stop));
}

// Julat tarikh daripada AI: satu hari, minggu ini/lepas, bulan ini
function rng(?array $ai, bool $defaultToday = true): array {
  $r = $ai['range'] ?? null; $d = $ai['date'] ?? null;
  if ($r === 'this_week') return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week')), 'this week'];
  if ($r === 'last_week') return [date('Y-m-d', strtotime('monday last week')), date('Y-m-d', strtotime('sunday last week')), 'last week'];
  if ($r === 'this_month') return [date('Y-m-01'), date('Y-m-t'), 'this month'];
  if ($d) return [$d, $d, $d === date('Y-m-d') ? 'today' : ($d === date('Y-m-d', strtotime('-1 day')) ? 'yesterday' : fmt_date($d))];
  return $defaultToday ? [date('Y-m-d'), date('Y-m-d'), 'today'] : [null, null, ''];
}
function mask(string $s): string { return preg_replace('/[\w.+-]+@[\w.-]+\.\w+/', '•••@•••', $s); }   // sembunyi alamat emel
function dp_match(PDO $pdo, int $doc, string $msg): array {   // pesakit milik doktor ini yang disebut dalam mesej
  $st = $pdo->prepare('SELECT DISTINCT p.id, p.username, p.matric_no FROM users p JOIN appointments a ON a.patient_id=p.id WHERE a.doctor_id=?'); $st->execute([$doc]);
  $tok = name_tokens($msg); $up = strtoupper($msg); $hits = [];
  foreach ($st->fetchAll() as $p) if (str_contains($up, strtoupper($p['matric_no'])) || array_intersect($tok, name_tokens($p['username']))) $hits[] = $p;
  return $hits;
}
function alines(array $rows, bool $forDoctor): array {   // senarai appointment dengan nama & sebab
  return array_map(fn($x) => fmt_date($x['appt_date']) . ' ' . fmt_time($x['appt_time']) . ' – ' . ($forDoctor ? $x['pn'] : $x['dn'] . ' ← ' . $x['pn']) . ' · ' . $x['status'] . ($x['followup_of'] ? ' · follow-up' : '') . ($x['reason'] ? ' · “' . mb_substr($x['reason'], 0, 45) . '”' : ''), $rows);
}

$help = $role === 'doctor'
  ? "I can help you with:\n" . bullets(["Your schedule (any date) and available hours", "Pending requests & your next patient", "Appointment history, consultation & follow-up counts, reasons", "Finding one of your patients (visit history only)", "Unread messages", "Note & message templates, and how-to guides"])
  : "I can help you with:\n" . bullets(["Failed emails: cause + how to fix", "Activity log by day (today, yesterday…) and search", "Appointments with doctor, patient, reason, follow-ups & consultations", "New messages in the system", "System overview, registrations, doctor workload, no-shows, security", "Announcement templates and how-to guides"]);
$quick = $role === 'doctor'
  ? [qk("Today's schedule", 'What is my schedule today?'), qk('Pending requests', 'Any pending requests?'), qk('My availability', 'Show my available schedule'), qk('Appointment history', 'Show my appointment history'), qk('New messages', 'Do I have new messages?')]
  : [qk('Email problems', 'Why are emails failing and how do I fix it?'), qk('Activity today', 'What activity happened today?'), qk('Activity yesterday', 'Show the activity log for yesterday'), qk("Today's appointments", 'List appointments today with reasons'), qk('New messages', 'Are there any new messages?')];

// ---------- 1) Perlindungan privasi (sebelum apa-apa) ----------
$intent = null; $ai = null;
if (preg_match('/\b(show|tell|give|reveal|display|read|list|dump|what\'?s|what is|tunjuk|beritahu|bagi|apa)\b.{0,50}\b(password|passwd|kata laluan|hash|token|secret|api key|enc_key|app password|diagnosis|medical history|rekod perubatan|sejarah perubatan|phone number|nombor telefon|ic number|mykad|home address|alamat rumah|private message|mesej peribadi)\b/iu', $msg)) $intent = 'privacy';
else {
  $ai = ai_post('/analyze', ['message' => $msg, 'doctors' => [], 'today' => date('Y-m-d'), 'role' => $role, 'knowledge' => ai_knowledge('staff')]);
  if (!$ai) out("My AI brain is offline right now 😴. Please start the Python server (and ngrok) and try again.\n\n" . $help, []);
  $intent = $ai['intent'] ?? 'unknown';
}
if ($intent === 'kb') { $kb = kb_answer((int)($ai['kb_id'] ?? 0), 'staff'); if ($kb !== null) out($kb); }
log_act('ai_staff', $intent);   // hanya nama niat direkod, bukan teks soalan
$date = $ai['date'] ?? date('Y-m-d'); $dl = $date === date('Y-m-d') ? 'today' : fmt_date($date);

switch ($intent) {
  case 'privacy':
    out("I can't help with that. 🔒 Passwords, keys, private messages, contact details and clinical notes are off-limits for the assistant.\n\nIf someone is locked out, an admin can set a new password from Patients/Doctors → Edit.", [qk('What can you do?', 'What can you do?')]);
  case 'greeting': out('Hello ' . $u['username'] . '! 👋 ' . ($role === 'doctor' ? 'Ready to speed up your clinic day?' : 'Ready to run a smooth clinic?'), $quick);
  case 'thanks': out('You are welcome! Anything else?');
  case 'staff_help': out($help, $quick);

  // ===================== DOKTOR =====================
  case 'doc_today':
    $st = $pdo->prepare("SELECT a.appt_time, a.status, a.reason, p.username pn, p.matric_no FROM appointments a JOIN users p ON p.id=a.patient_id WHERE a.doctor_id=? AND a.appt_date=? AND a.status NOT IN ('cancelled','rejected') ORDER BY a.appt_time");
    $st->execute([$id, $date]); $r = $st->fetchAll();
    out($r ? "Your schedule for $dl (" . count($r) . "):\n" . bullets(array_map(fn($x) => fmt_time($x['appt_time']) . ' – ' . $x['pn'] . ' (' . $x['matric_no'] . ') · ' . $x['status'] . ($x['reason'] ? ' · ' . mb_substr($x['reason'], 0, 40) : ''), $r)) : "You have no appointments $dl. 🎉",
      [lk('Open appointments', 'doctor/appointments.php')]);
  case 'doc_pending':
    $st = $pdo->prepare("SELECT a.appt_date, a.appt_time, p.username pn FROM appointments a JOIN users p ON p.id=a.patient_id WHERE a.doctor_id=? AND a.status='pending' ORDER BY a.appt_date, a.appt_time LIMIT 6");
    $st->execute([$id]); $r = $st->fetchAll(); $n = (int)qv("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND status='pending'", [$id]);
    out($n ? "$n request(s) waiting for your confirmation:\n" . bullets(array_map(fn($x) => $x['pn'] . ' – ' . fmt_date($x['appt_date']) . ' ' . fmt_time($x['appt_time']), $r)) . "\n\nUnconfirmed requests are auto-cancelled " . NOSHOW_AFTER_MIN . ' minutes after their start time.' : 'No pending requests. ✅', [lk('Review requests', 'doctor/appointments.php?f=pending')]);
  case 'doc_next':
    $st = $pdo->prepare("SELECT a.appt_date, a.appt_time, a.status, p.username pn, p.matric_no FROM appointments a JOIN users p ON p.id=a.patient_id WHERE a.doctor_id=? AND a.status IN ('confirmed','arrived') AND TIMESTAMP(a.appt_date,a.appt_time)>=NOW() ORDER BY a.appt_date, a.appt_time LIMIT 1");
    $st->execute([$id]); $x = $st->fetch();
    out($x ? 'Your next patient is ' . $x['pn'] . ' (' . $x['matric_no'] . ') on ' . fmt_date($x['appt_date']) . ' at ' . fmt_time($x['appt_time']) . '.' : 'No confirmed appointments ahead.', [lk('Open appointments', 'doctor/appointments.php')]);
  case 'doc_stats':
    $mon = date('Y-m-d', strtotime('monday this week')); $sun = date('Y-m-d', strtotime('sunday this week'));
    $c = fn($w, $p = []) => (int)qv("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND $w", array_merge([$id], $p));
    out("Your numbers:\n" . bullets(['Today: ' . $c("appt_date=CURDATE() AND status IN ('pending','confirmed','arrived')") . ' active', 'This week: ' . $c("appt_date BETWEEN ? AND ? AND status NOT IN ('cancelled','rejected')", [$mon, $sun]),
      'Completed this month: ' . $c("status='completed' AND appt_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01')"), 'No-shows this month: ' . $c("status='missed' AND appt_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01')"), 'Pending confirmation: ' . $c("status='pending'")]), [lk('Open appointments', 'doctor/appointments.php')]);
  case 'doc_followups':
    $st = $pdo->prepare("SELECT a.appt_date, a.appt_time, p.username pn FROM appointments a JOIN users p ON p.id=a.patient_id WHERE a.doctor_id=? AND a.followup_of IS NOT NULL AND a.status IN ('pending','confirmed') AND a.appt_date>=CURDATE() ORDER BY a.appt_date, a.appt_time LIMIT 8");
    $st->execute([$id]); $r = $st->fetchAll();
    out($r ? "Upcoming follow-up reviews:\n" . bullets(array_map(fn($x) => $x['pn'] . ' – ' . fmt_date($x['appt_date']) . ' ' . fmt_time($x['appt_time']), $r)) : 'No upcoming follow-ups. You can add one from a completed appointment.', [lk('Completed visits', 'doctor/appointments.php?f=completed')]);
  case 'doc_messages':
    $st = $pdo->prepare('SELECT s.username sn, COUNT(*) n FROM messages m JOIN users s ON s.id=m.sender_id WHERE m.receiver_id=? AND m.is_read=0 GROUP BY s.id ORDER BY n DESC LIMIT 8');
    $st->execute([$id]); $r = $st->fetchAll();
    out($r ? 'Unread messages from:' . "\n" . bullets(array_map(fn($x) => $x['sn'] . ' (' . $x['n'] . ')', $r)) . "\n\n(I only show who wrote, never the message content.)" : 'No unread messages. 📭', [lk('Open messages', 'messages.php')]);
  case 'doc_find_patient':
    $st = $pdo->prepare("SELECT p.id, p.username, p.matric_no, COUNT(a.id) visits, SUM(a.status='completed') done, MAX(CASE WHEN a.status='completed' THEN a.appt_date END) last_visit FROM users p JOIN appointments a ON a.patient_id=p.id WHERE a.doctor_id=? GROUP BY p.id");
    $st->execute([$id]); $tok = name_tokens($msg); $up = strtoupper($msg); $hits = [];
    foreach ($st->fetchAll() as $p) if (str_contains($up, strtoupper($p['matric_no'])) || array_intersect($tok, name_tokens($p['username']))) $hits[] = $p;
    if (!$hits) out("Tell me the patient's name or matric number. I can only look up patients who have booked with you, and I only share visit history — not clinical notes.");
    $lines = [];
    foreach (array_slice($hits, 0, 3) as $p) {
      $nx = qv("SELECT CONCAT(appt_date,' ',appt_time) FROM appointments WHERE doctor_id=? AND patient_id=? AND status IN ('pending','confirmed') AND TIMESTAMP(appt_date,appt_time)>=NOW() ORDER BY appt_date, appt_time LIMIT 1", [$id, $p['id']]);
      $lines[] = $p['username'] . ' (' . $p['matric_no'] . '): ' . $p['visits'] . ' booking(s), ' . (int)$p['done'] . ' completed' . ($p['last_visit'] ? ', last seen ' . fmt_date($p['last_visit']) : '') . ($nx ? ', next ' . fmt_date($nx) . ' ' . fmt_time(substr($nx, 11)) : ', nothing booked');
    }
    out(bullets($lines), [lk('Open appointments', 'doctor/appointments.php?f=all')]);

  // ===================== ADMIN =====================
  case 'adm_overview':
    $last = (int)qv("SELECT v FROM settings WHERE k='last_cron'");
    out("Clinic overview:\n" . bullets([(int)qv("SELECT COUNT(*) FROM users WHERE role='patient'") . ' patients · ' . (int)qv("SELECT COUNT(*) FROM users WHERE role='doctor' AND status='active'") . ' active doctors',
      'Appointments today: ' . (int)qv('SELECT COUNT(*) FROM appointments WHERE appt_date=CURDATE()') . ' · pending: ' . (int)qv("SELECT COUNT(*) FROM appointments WHERE status='pending'"),
      'Emails (24h): ' . (int)qv("SELECT COUNT(*) FROM activity_log WHERE action='email_sent' AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)") . ' sent, ' . (int)qv("SELECT COUNT(*) FROM activity_log WHERE action='email_failed' AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)") . ' failed',
      ((int)qv('SELECT COUNT(*) FROM sender_accounts') ? '✅ Email sender configured' : '⚠️ No email sender configured'), (AI_URL ? '✅ AI service linked' : '➖ AI service not linked'),
      ($last ? '✅ Reminders last ran ' . max(0, (int)round((time() - $last) / 60)) . ' min ago' : '➖ Reminders have not run yet')]), [lk('Dashboard', 'admin/dashboard.php')]);
  case 'adm_pending':
    $r = $pdo->query("SELECT a.appt_date, a.appt_time, p.username pn, d.username dn FROM appointments a JOIN users p ON p.id=a.patient_id JOIN users d ON d.id=a.doctor_id WHERE a.status='pending' ORDER BY a.appt_date, a.appt_time LIMIT 6")->fetchAll();
    $n = (int)qv("SELECT COUNT(*) FROM appointments WHERE status='pending'");
    out($n ? "$n pending booking(s). Earliest:\n" . bullets(array_map(fn($x) => fmt_date($x['appt_date']) . ' ' . fmt_time($x['appt_time']) . ' – ' . $x['pn'] . ' → ' . $x['dn'], $r)) : 'No pending bookings. ✅', [lk('Open appointments', 'admin/appointments.php?s=pending')]);
  case 'adm_email':
    [$f, $t, $lab] = rng($ai);
    if (!($ai['date'] ?? null) && !($ai['range'] ?? null)) { $f = date('Y-m-d', strtotime('-7 day')); $t = date('Y-m-d'); $lab = 'in the last 7 days'; }
    $has = (int)qv('SELECT COUNT(*) FROM sender_accounts');
    $q = $pdo->prepare("SELECT action, details, created_at FROM activity_log WHERE action IN ('email_sent','email_failed') AND DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC");
    $q->execute([$f, $t]); $rows = $q->fetchAll();
    $sent = 0; $fails = []; foreach ($rows as $x) { if ($x['action'] === 'email_sent') $sent++; else $fails[] = $x; }
    $C = [   // [regex, nama, punca, cara baiki]
      ['/no sender account/i', 'No sender configured', 'No Gmail sender has been added, so nothing can be sent.', 'Admin → Email Settings → add the Gmail address and App Password, then press “Send test email”.'],
      ['/could not authenticate|535|username and password not accepted|authenticat|5\.7\.8|5\.7\.9|application-specific|invalid credentials/i', 'Login rejected', 'Gmail refused the credentials: the App Password is wrong, revoked, or typed with the normal Gmail password.', 'Turn on 2-Step Verification, create a NEW App Password (16 characters), remove the old sender in Email Settings and add it again. Make sure the address matches the account that created the App Password.'],
      ['/daily|quota|5\.4\.5|sending limit|rate limit|too many|421|4\.7\.0/i', 'Sending limit', 'Gmail temporarily blocked sending because the daily/rate limit was reached.', 'Wait up to 24 hours, reduce volume, or add a second sender account. Avoid repeatedly pressing test email.'],
      ['/connect\(\) failed|timed out|timeout|failed to connect|unreachable|getaddrinfo|php_network|connection refused|could not connect/i', 'Cannot reach Gmail', 'The server could not open a connection to smtp.gmail.com on port 465 (no internet, firewall/antivirus, or the host blocks outgoing SMTP).', 'On XAMPP: check internet, allow PHP/Apache in the firewall or antivirus, try another network. On free hosting such as InfinityFree outgoing SMTP is often blocked — switch to an HTTP email API (e.g. Brevo/Resend) in includes/mailer.php.'],
      ['/ssl|tls|certificate|openssl|crypto/i', 'SSL/TLS problem', 'The secure connection could not be set up (OpenSSL missing or an outdated certificate bundle).', 'In XAMPP enable extension=openssl in php.ini and restart Apache; keep PHP updated.'],
      ['/recipient|invalid address|user unknown|does not exist|no such user|mailbox|5\.1\.1|550|553|must provide at least one/i', 'Bad recipient address', 'The patient/doctor email address is wrong or does not exist.', 'Admin → Patients/Doctors → Edit the person and correct their email address.'],
    ];
    $cnt = []; $fix = []; $unk = 0; $addr = [];
    foreach ($fails as $x) {
      $p = explode(' | ', $x['details'], 3); $err = $x['details']; $addr[$p[0] ?? ''] = ($addr[$p[0] ?? ''] ?? 0) + 1; $hit = false;
      foreach ($C as $c) if (preg_match($c[0], $err)) { $cnt[$c[1]] = ($cnt[$c[1]] ?? 0) + 1; $fix[$c[1]] = $c; $hit = true; break; }
      if (!$hit) $unk++;
    }
    $tot = $sent + count($fails); arsort($cnt);
    $o = ($has ? '✅ A sender is configured.' : '⚠️ No sender is configured — nothing can be sent.') . "\nEmails $lab: $sent sent, " . count($fails) . ' failed' . ($tot ? ' (' . round(count($fails) / $tot * 100) . '% failure rate)' : '') . '.';
    if (!$has && !$fails) $fix['No sender configured'] = $C[0];
    if (!$fails) out($o . "\nNo failures to diagnose. ✅" . (!$has ? "\n\nFix: " . $C[0][3] : ''), [lk('Email settings', 'admin/mail.php')]);
    $o .= "\n\nCauses found:\n" . bullets(array_merge(array_map(fn($k, $n) => "$k × $n — " . $fix[$k][2], array_keys($cnt), $cnt), $unk ? ["Unrecognised error × $unk — see the latest raw error below"] : []));
    $o .= $cnt ? "\n\nHow to fix:\n" . bullets(array_map(fn($k) => $fix[$k][3], array_slice(array_keys($cnt), 0, 3))) : "\n\nI could not match this error to a known cause. Send the latest error below to your developer.";
    $rep = count(array_filter($addr, fn($n) => $n > 1)); if ($rep) $o .= "\n\n$rep address(es) failed more than once — likely mistyped emails; check them in Patients/Doctors.";
    $okAfter = $pdo->query("SELECT 1 FROM activity_log WHERE action='email_sent' AND id>(SELECT COALESCE(MAX(id),0) FROM activity_log WHERE action='email_failed') LIMIT 1")->fetch();
    $o .= $okAfter ? "\n\n✅ An email was delivered after the latest failure, so the problem may already be resolved." : "\n\n⚠️ No successful email since the latest failure — the problem is still active.";
    $o .= "\nLatest error: " . mb_substr(mask($fails[0]['details']), 0, 160);
    out($o, [lk('Email settings', 'admin/mail.php'), lk('Failed emails log', 'admin/activity.php?a=email_failed')]);
  case 'adm_users':
    out("Accounts:\n" . bullets([(int)qv("SELECT COUNT(*) FROM users WHERE role='patient'") . ' patients (' . (int)qv("SELECT COUNT(*) FROM users WHERE role='patient' AND created_at>DATE_SUB(NOW(),INTERVAL 7 DAY)") . ' new in 7 days)',
      (int)qv("SELECT COUNT(*) FROM users WHERE role='doctor'") . ' doctors', (int)qv("SELECT COUNT(*) FROM users WHERE status='disabled'") . ' disabled accounts']), [lk('Patients', 'admin/users.php?role=patient')]);
  case 'adm_workload':
    $mon = date('Y-m-d', strtotime('monday this week')); $sun = date('Y-m-d', strtotime('sunday this week'));
    $st = $pdo->prepare("SELECT d.username, COUNT(a.id) n FROM users d LEFT JOIN appointments a ON a.doctor_id=d.id AND a.appt_date BETWEEN ? AND ? AND a.status NOT IN ('cancelled','rejected') WHERE d.role='doctor' AND d.status='active' GROUP BY d.id ORDER BY n DESC");
    $st->execute([$mon, $sun]); $r = $st->fetchAll();
    out($r ? "Appointments this week per doctor:\n" . bullets(array_map(fn($x) => $x['username'] . ': ' . $x['n'], $r)) : 'No active doctors yet.', [lk('Doctors', 'admin/users.php?role=doctor')]);
  case 'adm_noshow':
    $c = fn($s) => (int)qv("SELECT COUNT(*) FROM appointments WHERE status=? AND appt_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)", [$s]);
    $m = $c('missed'); $den = $m + $c('completed') + $c('arrived') + $c('confirmed');
    out('Last 30 days:' . "\n" . bullets(['No-show rate: ' . ($den ? round($m / $den * 100, 1) . "% ($m of $den)" : 'no data yet'), 'Cancelled: ' . $c('cancelled'), 'Declined by doctors: ' . $c('rejected')]), [lk('Appointments', 'admin/appointments.php?s=missed')]);
  case 'adm_security':
    $f = (int)qv("SELECT COUNT(*) FROM activity_log WHERE action='login_failed' AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)"); $ips = (int)qv("SELECT COUNT(DISTINCT ip) FROM activity_log WHERE action='login_failed' AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)");
    out("Last 24 hours:\n" . bullets(["Failed logins: $f from $ips IP address(es)", 'Successful logins: ' . (int)qv("SELECT COUNT(*) FROM activity_log WHERE action='login' AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)")]) . ($f >= 10 ? "\n⚠️ That is unusually high — review the activity log." : ''), [lk('Activity log', 'admin/activity.php?a=login_failed')]);
  case 'adm_find_user':
    $tok = name_tokens($msg); $up = preg_split('/[^A-Z0-9]+/', strtoupper($msg), -1, PREG_SPLIT_NO_EMPTY); $hits = [];
    foreach (array_slice(array_unique(array_merge($tok, [])), 0, 4) as $t) { $st = $pdo->prepare('SELECT id, role, matric_no, username, status, created_at FROM users WHERE LOWER(username) LIKE ? LIMIT 5'); $st->execute(["%$t%"]); foreach ($st->fetchAll() as $x) $hits[$x['id']] = $x; }
    foreach (array_slice(array_filter($up, fn($t) => strlen($t) >= 4), 0, 8) as $t) { $st = $pdo->prepare('SELECT id, role, matric_no, username, status, created_at FROM users WHERE matric_no=?'); $st->execute([$t]); foreach ($st->fetchAll() as $x) $hits[$x['id']] = $x; }
    if (!$hits) out('Give me a name or a matric/staff ID and I will look it up.');
    $chips = []; $l = [];
    foreach (array_slice($hits, 0, 4) as $x) { $l[] = $x['username'] . ' (' . $x['matric_no'] . ') · ' . $x['role'] . ' · ' . $x['status'] . ' · joined ' . fmt_date($x['created_at']); if ($x['role'] !== 'admin') $chips[] = lk('Edit ' . $x['username'], 'admin/users.php?role=' . $x['role'] . '&edit=' . $x['id']); }
    out(bullets($l) . "\n\n(Contact details and passwords are not shown here.)", $chips);

  // ===================== ADMIN: aktiviti, mesej, appointment =====================
  case 'adm_activity':
    [$f, $t, $lab] = rng($ai);
    $stop = ['activity', 'activities', 'log', 'logs', 'show', 'what', 'happened', 'today', 'yesterday', 'search', 'find', 'history', 'for', 'the', 'day', 'before', 'about', 'with', 'aktiviti', 'cari', 'semalam', 'hari', 'sebelum', 'ini', 'dalam', 'week', 'month', 'last', 'this', 'minggu', 'bulan'];
    $stop = array_merge($stop, ['please', 'all', 'any', 'were', 'there', 'was', 'happen', 'list', 'give', 'tell', 'from', 'and', 'you', 'can', 'events', 'event', 'records', 'record', 'system', 'are', 'did', 'does', 'how', 'many', 'who', 'when', 'where']);
    preg_match_all('/[a-z0-9_]{3,}/', strtolower($msg), $mm); $terms = array_values(array_diff($mm[0], $stop));
    $term = preg_match('/\b(search|find|cari|look ?up|filter|history|sejarah|containing|about|by|for)\b/i', $msg) ? ($terms[0] ?? null) : null;
    if ($term) {   // carian sejarah
      $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
      $q = $pdo->prepare("SELECT l.action, l.details, l.created_at, u.username FROM activity_log l LEFT JOIN users u ON u.id=l.user_id WHERE (l.action LIKE ? OR l.details LIKE ? OR u.username LIKE ?)" . (($ai['date'] ?? null) || ($ai['range'] ?? null) ? ' AND DATE(l.created_at) BETWEEN ? AND ?' : '') . " ORDER BY l.id DESC LIMIT 10");
      $q->execute(array_merge([$like, $like, $like], (($ai['date'] ?? null) || ($ai['range'] ?? null)) ? [$f, $t] : [])); $r = $q->fetchAll();
      out($r ? "Activity matching “$term”" . (($ai['date'] ?? null) || ($ai['range'] ?? null) ? " ($lab)" : '') . ":\n" . bullets(array_map(fn($x) => date('d M H:i', strtotime($x['created_at'])) . ' · ' . ($x['username'] ?? 'system') . ' · ' . $x['action'] . ' · ' . mb_substr(mask((string)$x['details']), 0, 60), $r)) : "No activity matches “$term”.", [lk('Open activity log', 'admin/activity.php?q=' . urlencode($term))]);
    }
    $q = $pdo->prepare('SELECT action, COUNT(*) n FROM activity_log WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY action ORDER BY n DESC LIMIT 10'); $q->execute([$f, $t]); $r = $q->fetchAll();
    $tot = (int)qv('SELECT COUNT(*) FROM activity_log WHERE DATE(created_at) BETWEEN ? AND ?', [$f, $t]); $users = (int)qv('SELECT COUNT(DISTINCT user_id) FROM activity_log WHERE DATE(created_at) BETWEEN ? AND ?', [$f, $t]);
    if (!$tot) out("No activity recorded $lab.", [lk('Open activity log', 'admin/activity.php')]);
    $o = "Activity $lab: $tot event(s) from $users user(s).\n" . bullets(array_map(fn($x) => $x['action'] . ' × ' . $x['n'], $r));
    if ($f === $t) {   // banding dengan hari sebelumnya
      $prev = date('Y-m-d', strtotime("$f -1 day")); $pt = (int)qv('SELECT COUNT(*) FROM activity_log WHERE DATE(created_at)=?', [$prev]); $d = $tot - $pt;
      $o .= "\n\nThe day before (" . fmt_date($prev) . "): $pt event(s) — " . ($d === 0 ? 'same as this day.' : ($d > 0 ? "+$d" : $d) . ' for this day.');
      $q = $pdo->prepare("SELECT l.action, l.details, l.created_at, u.username FROM activity_log l LEFT JOIN users u ON u.id=l.user_id WHERE DATE(l.created_at)=? AND l.action NOT IN ('email_sent','login','logout','ai_chat','ai_staff') ORDER BY l.id DESC LIMIT 5"); $q->execute([$f]);
      $n = $q->fetchAll(); if ($n) $o .= "\n\nLatest notable:\n" . bullets(array_map(fn($x) => date('H:i', strtotime($x['created_at'])) . ' · ' . ($x['username'] ?? 'system') . ' · ' . $x['action'] . ' · ' . mb_substr(mask((string)$x['details']), 0, 50), $n));
    } else {
      $q = $pdo->prepare('SELECT DATE(created_at) d, COUNT(*) n FROM activity_log WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY d ORDER BY d DESC LIMIT 8'); $q->execute([$f, $t]);
      $o .= "\n\nPer day:\n" . bullets(array_map(fn($x) => fmt_date($x['d']) . ': ' . $x['n'], $q->fetchAll()));
    }
    out($o . "\n\nTip: ask “search activity for login_failed” or “activity yesterday”.", [lk('Open activity log', 'admin/activity.php'), qk('Yesterday', 'Show the activity log for yesterday')]);
  case 'adm_messages':
    $win = ($ai['date'] ?? null) ? [$ai['date'], $ai['date'], fmt_date($ai['date'])] : [date('Y-m-d', strtotime('-1 day')), date('Y-m-d'), 'in the last 24 hours'];
    $unread = (int)qv('SELECT COUNT(*) FROM messages WHERE is_read=0'); $new = (int)qv('SELECT COUNT(*) FROM messages WHERE id>?', [seen_get("seen_{$id}_msg")]);
    $q = $pdo->prepare("SELECT s.username sn, r.username rn, COUNT(*) n, SUM(m.is_read=0) un, MAX(m.created_at) lastmsg FROM messages m JOIN users s ON s.id=m.sender_id JOIN users r ON r.id=m.receiver_id WHERE m.created_at>=? AND m.created_at<DATE_ADD(?, INTERVAL 1 DAY) GROUP BY m.sender_id, m.receiver_id ORDER BY lastmsg DESC LIMIT 8");
    $q->execute([$win[0] . ' 00:00:00', $win[1]]); $r = $q->fetchAll();
    out("Messages: $unread unread overall, $new new since you last opened Messages.\n" . ($r ? "\nConversations $win[2]:\n" . bullets(array_map(fn($x) => $x['sn'] . ' → ' . $x['rn'] . ': ' . $x['n'] . ' message(s)' . ($x['un'] ? ' (' . $x['un'] . ' unread)' : ''), $r)) . "\n\n(Only names and counts — I never read message content.)" : "\nNo messages $win[2]."), [lk('Open messages', 'admin/messages.php')]);
  case 'adm_appointments':
    [$f, $t, $lab] = rng($ai);
    $c = fn($w, $p = []) => (int)qv("SELECT COUNT(*) FROM appointments WHERE appt_date BETWEEN ? AND ? AND $w", array_merge([$f, $t], $p));
    $q = $pdo->prepare("SELECT a.*, p.username pn, d.username dn FROM appointments a JOIN users p ON p.id=a.patient_id JOIN users d ON d.id=a.doctor_id WHERE a.appt_date BETWEEN ? AND ? ORDER BY a.appt_date, a.appt_time LIMIT 12");
    $q->execute([$f, $t]); $r = $q->fetchAll();
    $o = "Appointments $lab: " . $c('1=1') . ' total · consultations completed ' . $c("status='completed'") . ' · follow-up reviews ' . $c('followup_of IS NOT NULL') . ' · pending ' . $c("status='pending'") . ' · missed ' . $c("status='missed'") . '.';
    $o .= $r ? "\n\n" . bullets(alines($r, false)) : "\n\nNothing scheduled.";
    $o .= "\n\nAll time: " . (int)qv("SELECT COUNT(*) FROM appointments WHERE status='completed'") . ' consultations, ' . (int)qv('SELECT COUNT(*) FROM appointments WHERE followup_of IS NOT NULL') . ' follow-up reviews.';
    out($o, [lk('Open appointments', 'admin/appointments.php')]);

  // ===================== DOKTOR: appointment, sejarah, jadual =====================
  case 'doc_schedule':
    $q = $pdo->prepare('SELECT weekday, start_time, end_time, slot_minutes FROM doctor_schedules WHERE doctor_id=? ORDER BY weekday, start_time'); $q->execute([$id]); $by = [];
    foreach ($q->fetchAll() as $x) $by[$x['weekday']][] = fmt_time($x['start_time']) . ' – ' . fmt_time($x['end_time']) . ' (' . $x['slot_minutes'] . ' min)';
    if (!$by) out('You have not set any availability yet, so patients cannot book you. Add your hours under My Schedule.', [lk('My Schedule', 'doctor/schedule.php')]);
    $dn = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']; $free = 0; $next = null;
    for ($i = 0; $i < 7; $i++) { $d = date('Y-m-d', strtotime("+$i day")); $sl = slots_for($id, $d); $free += count($sl); if ($sl && !$next) $next = [$d, $sl[0]]; }
    out("Your weekly availability:\n" . bullets(array_map(fn($k, $v) => $dn[$k] . ': ' . implode(', ', $v), array_keys($by), $by)) . "\n\nOpen (unbooked) slots in the next 7 days: $free" . ($next ? '. Next free: ' . fmt_date($next[0]) . ' ' . fmt_time($next[1]) . '.' : '.'), [lk('Edit schedule', 'doctor/schedule.php')]);
  case 'doc_appointments':
  case 'doc_history':
    $hist = $intent === 'doc_history'; $hits = dp_match($pdo, $id, $msg);
    $cc = fn($w, $p = []) => (int)qv("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND $w", array_merge([$id], $p));
    $cond = ['a.doctor_id=?']; $par = [$id]; $lab = '';
    if ($hits) { $cond[] = 'a.patient_id=?'; $par[] = (int)$hits[0]['id']; $lab = ' with ' . $hits[0]['username']; }
    [$f, $t, $rl] = rng($ai, false);
    if ($f) { $cond[] = 'a.appt_date BETWEEN ? AND ?'; array_push($par, $f, $t); $lab .= " ($rl)"; }
    elseif ($hist) $cond[] = "(a.status IN ('completed','missed','rejected','cancelled') OR a.appt_date<CURDATE())";
    else { $cond[] = 'a.appt_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)'; $lab .= ' (next 7 days)'; }
    $q = $pdo->prepare('SELECT a.*, p.username pn FROM appointments a JOIN users p ON p.id=a.patient_id WHERE ' . implode(' AND ', $cond) . ' ORDER BY a.appt_date ' . ($hist ? 'DESC' : 'ASC') . ', a.appt_time ' . ($hist ? 'DESC' : 'ASC') . ' LIMIT 10');
    $q->execute($par); $r = $q->fetchAll();
    $pf = $hits ? ' AND patient_id=' . (int)$hits[0]['id'] : '';
    $o = ($hist ? 'Appointment history' : 'Appointments') . $lab . ":\nConsultations completed: " . $cc("status='completed'$pf") . ' · Follow-up reviews: ' . $cc("followup_of IS NOT NULL$pf") . ' (' . $cc("followup_of IS NOT NULL AND appt_date>=CURDATE() AND status IN ('pending','confirmed')$pf") . ' upcoming) · No-shows: ' . $cc("status='missed'$pf") . '.';
    $o .= $r ? "\n\n" . bullets(alines($r, true)) : "\n\nNo appointments found.";
    out($o, [lk($hist ? 'Completed visits' : 'Open appointments', $hist ? 'doctor/appointments.php?f=completed' : 'doctor/appointments.php')]);

  // ===================== PANDUAN & TEMPLAT =====================
  case 'staff_howto':
    $H = [
      ['/add.*doctor|new doctor|tambah.*doktor|doktor.*baharu|添加.*医生/iu', 'admin', "Add a doctor: Admin → Doctors → “+ Add Doctor”. Fill in a Staff ID (they log in with it), name, email, specialty and a starting password. Doctors never register themselves.", [lk('Open Doctors', 'admin/users.php?role=doctor')]],
      ['/reset|password|kata laluan|lupa/iu', 'admin', "Reset a password: Patients or Doctors → Edit the person → type a new password → Save. Share it securely; they can change it later under Profile.", [lk('Patients', 'admin/users.php?role=patient')]],
      ['/email|gmail|sender|smtp|app password/iu', 'admin', "Email setup: Email Settings → add the Gmail address + a 16-character App Password (2-Step Verification must be on) → Save → “Send test email to me”.", [lk('Email settings', 'admin/mail.php')]],
      ['/\bai\b|ngrok|python/iu', 'admin', "AI service: run `python ai_server.py`, then `ngrok http 5000`, and paste the https URL into AI_URL in config.php. AI_KEY must match on both sides.", []],
      ['/availab|schedule|jadual|slot|hours|masa/iu', 'doctor', "Set availability: My Schedule → pick a day, from/to time and minutes per patient → Add availability. Patients only see slots inside these hours.", [lk('My Schedule', 'doctor/schedule.php')]],
      ['/follow|susulan|复诊/iu', 'doctor', "Follow-up: when completing a consultation, fill “Follow-up review” (date & time). Or open a Completed visit → “Add follow-up review”. The patient is emailed and gets reminders automatically.", [lk('Completed visits', 'doctor/appointments.php?f=completed')]],
      ['/complete|consult|record|diagnos|konsultasi|selesai/iu', 'doctor', "Complete a consultation: Appointments → a Confirmed/Arrived visit → “Complete consultation” → diagnosis + notes → Save. It appears in the patient's records.", [lk('Appointments', 'doctor/appointments.php')]],
      ['/accept|confirm|decline|pending|sahkan|terima/iu', 'doctor', "Confirm bookings: use Accept/Decline in Appointments → Needs confirmation, or the buttons in the email sent to you when a patient books.", [lk('Needs confirmation', 'doctor/appointments.php?f=pending')]],
      ['/remind|late|cron|lewat/iu', 'both', "Reminders: patients get an email " . REMIND_BEFORE_MIN . " min before, a “you are late” email after " . LATE_AFTER_MIN . " min, and the slot is released as missed after " . NOSHOW_AFTER_MIN . " min. They run automatically (about once a minute while the site is visited, or via cron.php).", []],
    ];
    foreach ($H as [$re, $who, $ans, $ch]) if (($who === 'both' || $who === $role) && preg_match($re, $msg)) out($ans, $ch);
    out('Which topic? ' . ($role === 'doctor' ? 'Availability, accepting bookings, completing a consultation, follow-ups or reminders.' : 'Adding a doctor, resetting a password, email setup, AI setup or reminders.'), $quick);
  case 'staff_template':
    if (preg_match('/note|soap|consult|nota|konsultasi|record/iu', $msg)) out("Consultation note (SOAP):\n\nS – Presenting complaint & duration:\nO – Observations / examination findings:\nA – Assessment / diagnosis:\nP – Plan (medication, advice, referral, follow-up date):\n\nTip: keep the Diagnosis field short and put the detail in Notes.");
    if (preg_match('/announce|notice|pengumuman|umum/iu', $msg)) out("Announcement draft:\n\nDear students and staff,\n\nPlease note that [change/closure/new service] will take effect on [date]. Appointments during [time period] [are affected / remain unchanged]. You can book or reschedule anytime through the website.\n\nThank you for your understanding.\n" . SITE_NAME);
    if (preg_match('/follow|susulan/iu', $msg)) out("Follow-up message:\n\nHi [Name], this is a reminder that your follow-up review is on [date] at [time]. Please bring [items, if any] and arrive 10 minutes early. If this time does not suit you, cancel in the app and book another slot.");
    out("Patient message drafts:\n\n1) Friendly reminder:\nHi [Name], just a reminder about your appointment on [date] at [time]. Please arrive 10 minutes early.\n\n2) Reschedule:\nHi [Name], I need to move your appointment. Could you pick another slot on the booking page? Apologies for the inconvenience.\n\n3) General advice:\nHi [Name], thank you for your message. [Advice]. If symptoms get worse, please book an appointment or call 999 in an emergency.", [qk('SOAP note template', 'Help me write a consultation note'), qk('Follow-up message', 'Draft a follow-up message')]);
}

// ---------- Soalan umum / tidak dikenali ----------
if (!preg_match('/@|\d{6,}/', $msg)) {   // jangan hantar emel / nombor panjang (telefon, IC) ke luar
  $r = ai_post('/chat', ['question' => $msg, 'role' => $role]);
  if (!empty($r['reply'])) out($r['reply'] . "\n\n— General AI answer. Please don't type patient names or personal details here.");
}
out("I'm not sure I understood 🤔.\n\n" . $help, $quick);
