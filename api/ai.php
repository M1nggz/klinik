<?php
// Proksi AI: PHP -> (ngrok) -> ai_server.py (sentence_transformers). Website kekal English.
require __DIR__ . '/../includes/boot.php';
header('Content-Type: application/json');
$u = me();
if (!$u || $u['role'] !== 'patient') { http_response_code(401); exit(json_encode(['reply' => 'Please log in as a patient to use the assistant.'])); }
csrf_check();
$msg = trim(mb_substr((json_decode(file_get_contents('php://input'), true)['message'] ?? ''), 0, 400));
if ($msg === '') exit(json_encode(['reply' => 'Please type a question.']));
log_act('ai_chat', $msg);

$docs = db()->query("SELECT id, username name, specialty, bio, (SELECT COUNT(*) FROM doctor_schedules s WHERE s.doctor_id=users.id) sched FROM users WHERE role='doctor' AND status='active'")->fetchAll();

function ai_analyze(string $msg, array $docs): ?array {
  if (!AI_URL) return null;
  $ch = curl_init(rtrim(AI_URL, '/') . '/analyze');
  curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . AI_KEY, 'ngrok-skip-browser-warning: 1'],
    CURLOPT_POSTFIELDS => json_encode(['message' => $msg, 'doctors' => $docs, 'today' => date('Y-m-d'), 'role' => 'patient', 'knowledge' => ai_knowledge('patient')])]);
  $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return ($r && $code === 200) ? json_decode($r, true) : null;
}

$bookChip = ['label' => 'Open booking page', 'url' => url('patient/book.php')];
$ai = ai_analyze($msg, $docs);
if (!$ai) exit(json_encode(['reply' => "My AI brain is offline right now 😴, but you can still book manually.", 'chips' => [$bookChip]]));

$faq = [
  'faq_hours' => clinic_hours(),
  'faq_register' => 'Patients register once with a username, email, matric number and password, then log in with matric number and password. Doctors are added by the admin and log in directly.',
  'faq_reminder' => 'After you book, we email you the details. Your doctor then confirms by email. We also send a reminder before your appointment and a warning if you are running late. Slots are released after ' . NOSHOW_AFTER_MIN . ' minutes.',
  'faq_contact_doctor' => 'You can message any doctor from the Messages page.',
  'faq_emergency' => '🚨 If this is an emergency, call 999 right now — please do not wait for an appointment. If you are thinking of harming yourself, call Talian HEAL at 15555 or go to the nearest emergency department. You can also tell a friend or staff member nearby.',
  'greeting' => 'Hello! 👋 I can find a doctor and free slots for you. Try “I want to see a doctor tomorrow”.',
  'privacy' => 'I can’t share private or account details (passwords, contact details, other people’s records). I can help you book, check or cancel appointments, and answer questions about the clinic.',
  'thanks' => 'You are welcome! Anything else I can help with?',
];
$intent = $ai['intent'] ?? 'unknown';
if ($intent === 'kb') { $kb = kb_answer((int)($ai['kb_id'] ?? 0), 'patient'); if ($kb !== null) exit(json_encode(['reply' => $kb], JSON_UNESCAPED_UNICODE)); }
if ($intent === 'faq_contact_doctor') exit(json_encode(['reply' => $faq[$intent], 'chips' => [['label' => 'Open messages', 'url' => url('messages.php')]]]));
if (isset($faq[$intent])) exit(json_encode(['reply' => $faq[$intent]]));

// ===================== Data pesakit sendiri (hanya akaun yang sedang log masuk) =====================
$pid = (int)$u['id'];
$ST = ['pending' => 'waiting for the doctor', 'confirmed' => 'confirmed', 'arrived' => 'checked in', 'completed' => 'completed', 'rejected' => 'declined by the doctor', 'cancelled' => 'cancelled', 'missed' => 'missed'];
function pa_rows(string $where, string $order = 'DESC', int $lim = 8): array {
  global $pid;
  $q = db()->prepare("SELECT a.*, d.username dn, d.specialty sp FROM appointments a JOIN users d ON d.id=a.doctor_id WHERE a.patient_id=? AND $where ORDER BY a.appt_date $order, a.appt_time $order LIMIT $lim");
  $q->execute([$pid]); return $q->fetchAll();
}
function pa_line(array $r): string {
  global $ST;
  return '• ' . fmt_date($r['appt_date']) . ' ' . fmt_time($r['appt_time']) . ' – ' . $r['dn'] . ' (' . $r['sp'] . ') · ' . $ST[$r['status']] . ($r['followup_of'] ? ' · follow-up' : '') . ($r['reason'] ? ' · “' . mb_substr($r['reason'], 0, 40) . '”' : '');
}
function pa_count(string $where): int { global $pid; return (int)qv("SELECT COUNT(*) FROM appointments WHERE patient_id=? AND $where", [$pid]); }
function reply(string $t, array $chips = []): void { exit(json_encode(['reply' => $t, 'chips' => $chips], JSON_UNESCAPED_UNICODE)); }
function pchip(string $l, string $p): array { return ['label' => $l, 'url' => url($p)]; }
$myAppts = pchip('My appointments', 'patient/appointments.php');

if (in_array($intent, ['my_appointments', 'pat_current', 'cancel_appointment'], true)) {
  $r = pa_rows("a.status IN ('pending','confirmed','arrived') AND a.appt_date>=CURDATE()", 'ASC', 8);
  $p = pa_count("status='pending' AND appt_date>=CURDATE()"); $c = pa_count("status IN ('confirmed','arrived') AND appt_date>=CURDATE()");
  $t = $r ? "Appointments in progress ($p waiting for the doctor, $c confirmed):\n" . implode("\n", array_map('pa_line', $r)) : 'You have no appointments in progress right now.';
  if ($intent === 'cancel_appointment') $t .= "\n\nTo cancel, open My Appointments and tap Cancel.";
  reply($t, [$myAppts, pchip('Book new', 'patient/book.php')]);
}
if ($intent === 'pat_missed') {
  $r = pa_rows("a.status='missed'"); $n = pa_count("status='missed'");
  reply($n ? "You have $n missed appointment(s). Latest:\n" . implode("\n", array_map('pa_line', $r)) . "\n\nSlots are released " . NOSHOW_AFTER_MIN . ' minutes after the start time if you have not arrived.' : 'You have not missed any appointments. 👏', [pchip('Book a new slot', 'patient/book.php'), $myAppts]);
}
if ($intent === 'pat_rejected') {
  $r = pa_rows("a.status='rejected'"); $n = pa_count("status='rejected'"); $cx = pa_count("status='cancelled'");
  reply(($n ? "$n appointment(s) were declined by the doctor:\n" . implode("\n", array_map('pa_line', $r)) . "\n\nYou can book another time with the same doctor." : 'None of your appointments were declined.') . ($cx ? "\n($cx cancelled)" : ''), [pchip('Book another slot', 'patient/book.php'), $myAppts]);
}
if ($intent === 'pat_history') {
  $r = pa_rows("(a.status IN ('completed','missed','rejected','cancelled') OR a.appt_date<CURDATE())", 'DESC', 10);
  $sum = 'Completed ' . pa_count("status='completed'") . ' · Missed ' . pa_count("status='missed'") . ' · Declined ' . pa_count("status='rejected'") . ' · Cancelled ' . pa_count("status='cancelled'");
  reply($r ? "Your appointment history ($sum):\n" . implode("\n", array_map('pa_line', $r)) : 'You have no past appointments yet.', [$myAppts]);
}
if ($intent === 'pat_followup') {
  $up = pa_rows("a.followup_of IS NOT NULL AND a.appt_date>=CURDATE() AND a.status IN ('pending','confirmed')", 'ASC'); $past = pa_count("followup_of IS NOT NULL AND (appt_date<CURDATE() OR status IN ('completed','missed','cancelled','rejected'))");
  reply($up || $past ? ($up ? "Upcoming follow-up review(s):\n" . implode("\n", array_map('pa_line', $up)) : 'No upcoming follow-up reviews.') . "\nPast follow-ups: $past" : 'You have no follow-up reviews. Your doctor can schedule one when completing a consultation.', [$myAppts]);
}
if ($intent === 'pat_consult') {
  $q = db()->prepare("SELECT a.appt_date, a.reason, d.username dn, r.diagnosis, r.notes FROM appointments a JOIN users d ON d.id=a.doctor_id LEFT JOIN medical_records r ON r.appointment_id=a.id WHERE a.patient_id=? AND a.status='completed' ORDER BY a.appt_date DESC, a.appt_time DESC LIMIT 5");
  $q->execute([$pid]); $r = $q->fetchAll(); $n = pa_count("status='completed'");
  reply($r ? "You have had $n consultation(s). Latest:\n" . implode("\n", array_map(fn($x) => '• ' . fmt_date($x['appt_date']) . ' – ' . $x['dn'] . ($x['reason'] ? ' · reason “' . mb_substr($x['reason'], 0, 40) . '”' : '') . ($x['diagnosis'] ? ' · diagnosis: ' . $x['diagnosis'] : '') . ($x['notes'] ? ' · notes: ' . mb_substr($x['notes'], 0, 80) : ''), $r)) : 'No completed consultations yet.', [pchip('Medical records', 'patient/records.php'), $myAppts]);
}
if ($intent === 'pat_records') {
  $q = db()->prepare('SELECT r.diagnosis, r.notes, r.created_at, d.username dn FROM medical_records r JOIN users d ON d.id=r.doctor_id WHERE r.patient_id=? ORDER BY r.created_at DESC LIMIT 6');
  $q->execute([$pid]); $r = $q->fetchAll(); $n = (int)qv('SELECT COUNT(*) FROM medical_records WHERE patient_id=?', [$pid]);
  reply($r ? "Your medical records ($n):\n" . implode("\n", array_map(fn($x) => '• ' . fmt_date($x['created_at']) . ' – ' . $x['diagnosis'] . ' (' . $x['dn'] . ')' . ($x['notes'] ? ' · ' . mb_substr($x['notes'], 0, 100) : ''), $r)) : 'You have no medical records yet. They appear after a doctor completes your consultation.', [pchip('Open records', 'patient/records.php')]);
}
if ($intent === 'pat_messages') {
  $q = db()->prepare('SELECT s.id, s.username, COUNT(*) n FROM messages m JOIN users s ON s.id=m.sender_id WHERE m.receiver_id=? AND m.is_read=0 GROUP BY s.id ORDER BY n DESC LIMIT 6');
  $q->execute([$pid]); $r = $q->fetchAll();
  reply($r ? 'You have unread messages from:' . "\n" . implode("\n", array_map(fn($x) => '• ' . $x['username'] . ' (' . $x['n'] . ')', $r)) : 'No new messages. 📭', $r ? [pchip('Read ' . $r[0]['username'], 'messages.php?with=' . $r[0]['id'])] : [pchip('Open messages', 'messages.php')]);
}

if (in_array($intent, ['book_appointment', 'check_availability', 'find_doctor', 'describe_symptom'], true)) {
  $specs = array_values(array_unique(array_filter(array_column($docs, 'specialty'))));
  if (empty($ai['doctor_id']) && empty($ai['specialty']) && !empty($ai['requested'])) {   // kepakaran yang diminta belum wujud
    exit(json_encode(['reply' => 'We don’t have a “' . $ai['requested'] . '” on our team right now' . ($specs ? '. Our current specialties: ' . implode(', ', $specs) . '.' : '.') . "\n\nThis list updates automatically as soon as a new doctor is added.",
      'chips' => array_map(fn($x) => ['label' => $x, 'send' => 'I want to see ' . $x], array_slice($specs, 0, 6))], JSON_UNESCAPED_UNICODE));
  }
  $cand = array_values(array_filter($docs, fn($d) => !empty($ai['doctor_id']) ? (int)$d['id'] === (int)$ai['doctor_id'] : (empty($ai['specialty']) || $d['specialty'] === $ai['specialty'])));
  if (!$cand) $cand = $docs;
  $lead = ''; $sym = $intent === 'describe_symptom';
  if ($sym) {   // pesakit terangkan simptom -> cadangkan doktor yang sesuai
    if (!empty($ai['specialty']) || !empty($ai['doctor_id'])) $lead = 'I’m sorry you’re going through this. 💙 Based on what you described, I’d suggest ' . (!empty($ai['specialty']) ? 'a ' . $ai['specialty'] : 'this doctor') . ".\n\n";
    else {
      $gp = array_values(array_filter($docs, fn($d) => preg_match('/general|practition|family|primary/i', (string)$d['specialty'])));
      if ($gp) $cand = $gp;
      $lead = 'I’m sorry you’re going through this. 💙 ' . (!empty($ai['symptom']) ? 'We don’t currently have a specialist for that, so ' : 'I can’t tell exactly which specialist you need, so ') . ($gp ? 'a General Practitioner is a good first step' : 'here are our doctors') . ".\n\n";
    }
  }
  $tail = $sym ? "\n\n⚠️ I can’t diagnose — this is only a suggestion. If it feels severe or urgent, call 999." : '';
  $pref = $ai['time_pref'] ?? null;
  $match = function (string $hm) use ($pref) {
    if (!$pref) return true;
    if ($pref === 'morning') return $hm < '12:00';
    if ($pref === 'afternoon') return $hm >= '12:00' && $hm < '17:00';
    if ($pref === 'evening') return $hm >= '17:00';
    return abs((int)substr($hm, 0, 2) - (int)substr($pref, 0, 2)) <= 1;
  };
  $date = $ai['date'] ?? null; $explicit = (bool)$date; $date = $date && $date >= date('Y-m-d') ? $date : date('Y-m-d');
  $chips = []; $lines = []; $useDate = null;
  for ($i = 0; $i < ($explicit ? 1 : 14) && !$chips; $i++) {
    $d = date('Y-m-d', strtotime("$date +$i day"));
    foreach (array_slice($cand, 0, 4) as $doc) {
      $sl = array_values(array_filter(slots_for((int)$doc['id'], $d), $match));
      if (!$sl) continue;
      $useDate = $d; $lines[] = '• ' . $doc['name'] . ' (' . $doc['specialty'] . ')';
      foreach (array_slice($sl, 0, 3) as $t) $chips[] = ['label' => preg_replace('/^Dr\.?\s*/i', '', $doc['name']) . ' · ' . fmt_time($t), 'url' => url('patient/book.php?doctor=' . $doc['id'] . '&date=' . $d . '&time=' . $t)];
    }
  }
  if ($chips) {
    exit(json_encode(['reply' => $lead . ($explicit ? 'Here are the free slots on ' : 'The earliest available day is ') . fmt_date($useDate) . ":\n" . implode("\n", $lines) . "\n\nTap a time to continue, then confirm your booking." . $tail, 'chips' => array_slice($chips, 0, 8)], JSON_UNESCAPED_UNICODE));
  }
  // Tiada slot: terangkan keadaan sebenar setiap doktor (cth doktor baharu belum set jadual)
  $l = []; $c2 = [];
  foreach (array_slice($cand, 0, 4) as $doc) {
    $n = $doc['sched'] ? next_available((int)$doc['id'], date('Y-m-d', strtotime(max($date, date('Y-m-d')) . ' +1 day')), 45) : null;
    $l[] = '• ' . $doc['name'] . ' (' . $doc['specialty'] . ') – ' . ($n ? 'next free ' . fmt_date($n) : ($doc['sched'] ? 'no free slots in the coming weeks' : 'has not published availability yet'));
    $c2[] = $n ? ['label' => $doc['name'] . ' · ' . fmt_date($n), 'url' => url('patient/book.php?doctor=' . $doc['id'] . '&date=' . $n)] : ['label' => 'Message ' . $doc['name'], 'url' => url('messages.php?with=' . $doc['id'])];
  }
  exit(json_encode(['reply' => $lead . "There are no matching free slots right now:\n" . implode("\n", $l) . $tail, 'chips' => $c2], JSON_UNESCAPED_UNICODE));
}

// Tidak difahami: simpan soalan (tanpa emel/nombor panjang) supaya admin boleh ajar AI
if (!preg_match('/@|\d{6,}/', $msg)) { try { db()->prepare('INSERT INTO ai_unanswered (message) VALUES (?)')->execute([mb_substr($msg, 0, 300)]); } catch (Throwable $e) {} }
exit(json_encode(['reply' => "I'm not sure I understood 🤔. I can help you book an appointment, find a doctor, check your bookings, or answer questions about reminders and registration.", 'chips' => [$bookChip]], JSON_UNESCAPED_UNICODE));
