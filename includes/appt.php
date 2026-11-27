<?php
// ---------- Slot kosong ----------
function slots_for(int $doctor_id, string $date): array {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d')) return [];
  $st = db()->prepare('SELECT start_time, end_time, slot_minutes FROM doctor_schedules WHERE doctor_id=? AND weekday=? ORDER BY start_time');
  $st->execute([$doctor_id, (int)date('N', strtotime($date))]);
  $taken = db()->prepare("SELECT TIME_FORMAT(appt_time,'%H:%i') t FROM appointments WHERE doctor_id=? AND appt_date=? AND status IN ('pending','confirmed','arrived')");
  $taken->execute([$doctor_id, $date]);
  $taken = array_column($taken->fetchAll(), 't');
  $out = []; $now = date('H:i');
  foreach ($st->fetchAll() as $s) {
    $t = strtotime($date . ' ' . $s['start_time']); $end = strtotime($date . ' ' . $s['end_time']);
    for (; $t + $s['slot_minutes'] * 60 <= $end; $t += $s['slot_minutes'] * 60) {
      $hm = date('H:i', $t);
      if (in_array($hm, $taken, true)) continue;
      if ($date === date('Y-m-d') && $hm <= $now) continue;
      $out[] = $hm;
    }
  }
  return $out;
}

function next_available(int $doctor_id, string $from, int $days = 21): ?string {
  for ($i = 0; $i < $days; $i++) {
    $d = date('Y-m-d', strtotime("$from +$i day"));
    if (slots_for($doctor_id, $d)) return $d;
  }
  return null;
}

// ---------- Appointment ----------
function appt_full(int $id): ?array {
  $st = db()->prepare('SELECT a.*, p.username patient_name, p.email patient_email, p.matric_no,
      d.username doctor_name, d.email doctor_email, d.specialty
    FROM appointments a JOIN users p ON p.id=a.patient_id JOIN users d ON d.id=a.doctor_id WHERE a.id=?');
  $st->execute([$id]);
  return $st->fetch() ?: null;
}

// Emel automatik selepas patient buat booking: ke patient + ke doktor (dengan pautan pengesahan)
function notify_new_booking(int $id): void {
  $a = appt_full($id); if (!$a) return;
  send_mail($a['patient_email'], $a['patient_name'], 'Appointment request received – ' . fmt_date($a['appt_date']),
    mail_layout('Booking successful 🎉',
      '<p>Hi ' . h($a['patient_name']) . ', your appointment request has been submitted. Your doctor will confirm it shortly and you will get another email.</p>' . appt_table($a),
      'View my appointments', BASE_URL . '/patient/appointments.php'));
  $link = BASE_URL . '/confirm.php?t=' . $a['token'];
  send_mail($a['doctor_email'], $a['doctor_name'], 'New appointment request from ' . $a['patient_name'],
    mail_layout('New appointment request',
      '<p>Hello ' . h($a['doctor_name']) . ', a patient has booked an appointment with you. Please confirm or decline using the buttons below.</p>' . appt_table($a, true),
      'Review & Accept', $link . '&a=accept', 'Decline', $link . '&a=decline'));
}

// Tukar status + hantar emel kepada pihak berkaitan
function set_status(int $id, string $status, string $why = ''): void {
  db()->prepare('UPDATE appointments SET status=? WHERE id=?')->execute([$status, $id]);
  log_act('appointment_' . $status, "#$id $why");
  if (in_array($status, ['confirmed', 'rejected', 'missed', 'completed', 'cancelled'], true))
    db()->prepare($why === 'patient' ? 'UPDATE appointments SET doctor_seen=0 WHERE id=?' : 'UPDATE appointments SET patient_seen=0 WHERE id=?')->execute([$id]);
  $a = appt_full($id); if (!$a) return;
  $btn = ['My appointments', BASE_URL . '/patient/appointments.php'];
  switch ($status) {
    case 'confirmed':
      send_mail($a['patient_email'], $a['patient_name'], 'Appointment confirmed – ' . fmt_date($a['appt_date']),
        mail_layout('Your appointment is confirmed ✅', '<p>Great news! ' . h($a['doctor_name']) . ' has accepted your appointment. Please arrive 10 minutes early.</p>' . appt_table($a), ...$btn)); break;
    case 'rejected':
      send_mail($a['patient_email'], $a['patient_name'], 'Appointment could not be confirmed',
        mail_layout('Appointment declined', '<p>Sorry, ' . h($a['doctor_name']) . ' is unable to take this slot. Please book another time.</p>' . appt_table($a), 'Book another slot', BASE_URL . '/patient/book.php')); break;
    case 'cancelled':
      if ($why !== 'patient') send_mail($a['patient_email'], $a['patient_name'], 'Appointment cancelled',
        mail_layout('Appointment cancelled', '<p>This appointment has been cancelled.</p>' . appt_table($a), 'Book again', BASE_URL . '/patient/book.php'));
      else send_mail($a['doctor_email'], $a['doctor_name'], 'Patient cancelled an appointment',
        mail_layout('Appointment cancelled by patient', '<p>' . h($a['patient_name']) . ' cancelled this appointment.</p>' . appt_table($a, true))); break;
    case 'missed':
      send_mail($a['patient_email'], $a['patient_name'], 'You missed your appointment',
        mail_layout('Appointment missed', '<p>We did not see you at your appointment and the slot has been released. You can book a new one anytime.</p>' . appt_table($a), 'Book a new slot', BASE_URL . '/patient/book.php')); break;
  }
}

// ---------- Peringatan automatik (hampir lewat / lewat / terlepas) ----------
function run_reminders(): array {
  $pdo = db(); $n = ['reminder' => 0, 'late' => 0, 'missed' => 0, 'expired' => 0];
  $ts = 'TIMESTAMP(appt_date, appt_time)';
  $ids = fn($sql) => array_column($pdo->query($sql)->fetchAll(), 'id');

  // 1) Terlepas: confirmed tetapi tak hadir selepas NOSHOW_AFTER_MIN
  foreach ($ids("SELECT id FROM appointments WHERE status='confirmed' AND $ts < DATE_SUB(NOW(), INTERVAL " . NOSHOW_AFTER_MIN . " MINUTE)") as $id) { set_status((int)$id, 'missed', 'auto'); $n['missed']++; }
  // 2) Tak disahkan doktor sehingga masa tamat
  foreach ($ids("SELECT id FROM appointments WHERE status='pending' AND $ts < DATE_SUB(NOW(), INTERVAL " . NOSHOW_AFTER_MIN . " MINUTE)") as $id) { set_status((int)$id, 'cancelled', 'expired'); $n['expired']++; }

  // 3) Peringatan: appointment akan bermula dalam REMIND_BEFORE_MIN minit
  foreach ($ids("SELECT id FROM appointments WHERE status='confirmed' AND reminder_sent=0 AND $ts BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL " . REMIND_BEFORE_MIN . " MINUTE)") as $id) {
    $a = appt_full((int)$id);
    $mins = max(1, (int)round((strtotime($a['appt_date'] . ' ' . $a['appt_time']) - time()) / 60));
    send_mail($a['patient_email'], $a['patient_name'], 'Reminder: your appointment starts in ' . $mins . ' minutes',
      mail_layout('Your appointment is almost here ⏰', '<p>Hi ' . h($a['patient_name']) . ', your appointment starts in about <b>' . $mins . ' minutes</b>. Please arrive on time — you will be marked late after ' . LATE_AFTER_MIN . ' minutes and the slot is released after ' . NOSHOW_AFTER_MIN . ' minutes.</p>' . appt_table($a)));
    $pdo->prepare('UPDATE appointments SET reminder_sent=1 WHERE id=?')->execute([$id]); $n['reminder']++;
  }
  // 4) Lewat: dah mula LATE_AFTER_MIN minit lepas tapi belum hadir
  foreach ($ids("SELECT id FROM appointments WHERE status='confirmed' AND late_sent=0 AND $ts <= DATE_SUB(NOW(), INTERVAL " . LATE_AFTER_MIN . " MINUTE)") as $id) {
    $a = appt_full((int)$id);
    $deadline = strtotime($a['appt_date'] . ' ' . $a['appt_time']) + NOSHOW_AFTER_MIN * 60;
    $left = max(1, (int)round(($deadline - time()) / 60));
    send_mail($a['patient_email'], $a['patient_name'], 'You are late for your appointment!',
      mail_layout('You are running late ⚠️', '<p>Hi ' . h($a['patient_name']) . ', your appointment started at <b>' . fmt_time($a['appt_time']) . '</b> and we have not seen you yet. Your slot will be released at <b>' . date('g:i A', $deadline) . '</b> (about <b>' . $left . ' minutes</b> left).</p>' . appt_table($a)));
    $pdo->prepare('UPDATE appointments SET late_sent=1 WHERE id=?')->execute([$id]); $n['late']++;
  }
  return $n;
}

// Dijalankan sekali seminit pada setiap lawatan halaman (sesuai untuk hosting percuma tanpa cron)
function run_reminders_throttled(): void {
  try {
    $pdo = db();
    $last = (int)($pdo->query("SELECT v FROM settings WHERE k='last_cron'")->fetchColumn() ?: 0);
    if (time() - $last < 60) return;
    $pdo->prepare("REPLACE INTO settings (k, v) VALUES ('last_cron', ?)")->execute([time()]);
    run_reminders();
  } catch (Throwable $e) {}
}


// ---------- Follow-up review (doktor) ----------
function schedule_followup(array $a, string $date, string $time, string $note = ''): string {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}/', $time)) return 'Please enter a valid follow-up date and time.';
  $time = substr($time, 0, 5);
  if (strtotime("$date $time") <= time()) return 'The follow-up must be in the future.';
  $c = db()->prepare("SELECT 1 FROM appointments WHERE doctor_id=? AND appt_date=? AND appt_time=? AND status IN ('pending','confirmed','arrived')");
  $c->execute([$a['doctor_id'], $date, $time]);
  if ($c->fetch()) return 'You already have an appointment at that date and time.';
  $reason = 'Follow-up review' . ($note !== '' ? ': ' . $note : '');
  db()->prepare("INSERT INTO appointments (patient_id, doctor_id, appt_date, appt_time, reason, status, token, followup_of, patient_seen) VALUES (?,?,?,?,?,'confirmed',?,?,0)")
      ->execute([$a['patient_id'], $a['doctor_id'], $date, $time, mb_substr($reason, 0, 250), bin2hex(random_bytes(16)), $a['id']]);
  $nid = (int)db()->lastInsertId();
  log_act('followup_scheduled', "#$nid from #{$a['id']} $date $time");
  $n = appt_full($nid);
  send_mail($n['patient_email'], $n['patient_name'], 'Follow-up review scheduled – ' . fmt_date($date),
    mail_layout('Follow-up review scheduled 🩺',
      '<p>Hi ' . h($n['patient_name']) . ', ' . h($n['doctor_name']) . ' has scheduled a follow-up review for you after your consultation.</p>'
      . ($note !== '' ? '<p><b>Doctor’s note:</b> ' . h($note) . '</p>' : '') . appt_table($n)
      . '<p>You will receive a reminder before the appointment. If this time does not suit you, cancel it and book another slot.</p>',
      'View my appointments', BASE_URL . '/patient/appointments.php'));
  return '';
}

// Kotak konsultasi (diagnosis, nota, follow-up) untuk appointment yang sudah selesai
function consult_block(int $apptId): string {
  $s = db()->prepare('SELECT r.*, d.username doctor_name FROM medical_records r JOIN users d ON d.id=r.doctor_id WHERE r.appointment_id=? ORDER BY r.id DESC LIMIT 1');
  $s->execute([$apptId]); $r = $s->fetch();
  $f = db()->prepare('SELECT appt_date, appt_time, status FROM appointments WHERE followup_of=? ORDER BY id DESC LIMIT 1');
  $f->execute([$apptId]); $fu = $f->fetch();
  if (!$r && !$fu) return '<div class="consult muted">No consultation notes were recorded for this visit.</div>';
  $o = '<div class="consult">';
  if ($r) $o .= '<div class="row sp"><b>📝 Consultation</b><small class="muted">' . h($r['doctor_name']) . ' · ' . fmt_date($r['created_at']) . '</small></div>'
              . '<p><b>Diagnosis:</b> ' . h($r['diagnosis']) . '</p>' . ($r['notes'] ? '<p><b>Notes:</b> ' . nl2br(h($r['notes'])) . '</p>' : '');
  if ($fu) $o .= '<p class="fu">🔁 <b>Follow-up review:</b> ' . fmt_date($fu['appt_date']) . ' at ' . fmt_time($fu['appt_time']) . ' <span class="badge b-' . h($fu['status']) . '">' . h($fu['status']) . '</span></p>';
  return $o . '</div>';
}
