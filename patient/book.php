<?php
require __DIR__ . '/../includes/layout.php';
$u = require_login('patient');
$docs = db()->query("SELECT id, username, specialty FROM users WHERE role='doctor' AND status='active' ORDER BY username")->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $doc = (int)($_POST['doctor_id'] ?? 0); $date = $_POST['date'] ?? ''; $time = $_POST['time'] ?? ''; $reason = trim(mb_substr($_POST['reason'] ?? '', 0, 250));
  if (!in_array($time, slots_for($doc, $date), true)) { flash('err', 'Sorry, that slot is no longer available. Please pick another.'); }
  else {
    db()->prepare('INSERT INTO appointments (patient_id, doctor_id, appt_date, appt_time, reason, token) VALUES (?,?,?,?,?,?)')
        ->execute([$u['id'], $doc, $date, $time, $reason, bin2hex(random_bytes(16))]);
    $id = (int)db()->lastInsertId(); log_act('appointment_booked', "#$id doctor $doc $date $time");
    notify_new_booking($id);
    flash('ok', 'Booking successful! A confirmation email was sent to ' . $u['email'] . '.'); redirect('patient/appointments.php');
  }
}
$selDoc = (int)($_POST['doctor_id'] ?? $_GET['doctor'] ?? 0); $selDate = $_POST['date'] ?? $_GET['date'] ?? date('Y-m-d'); $selTime = $_POST['time'] ?? $_GET['time'] ?? '';
page_start('Book Appointment'); ?>
<form class="card" method="post" id="bookForm" data-time="<?= h($selTime) ?>"><?= csrf_field() ?>
  <h3>1. Choose your doctor</h3>
  <div class="docpick" style="margin:12px 0 24px"><?php foreach ($docs as $d): ?>
    <label><input type="radio" name="doctor_id" value="<?= $d['id'] ?>" <?= $selDoc === (int)$d['id'] ? 'checked' : '' ?> required><div><b><?= h($d['username']) ?></b><br><small class="muted"><?= h($d['specialty']) ?></small></div></label><?php endforeach; ?></div>
  <h3>2. Select date &amp; time</h3>
  <div class="field" style="max-width:260px;margin-top:12px"><input type="date" id="date" name="date" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+60 days')) ?>" value="<?= h($selDate) ?>" required></div>
  <div class="slots" id="slots"></div>
  <h3 class="mt">3. Reason for visit <small class="muted">(optional)</small></h3>
  <div class="field" style="margin-top:12px"><textarea name="reason" placeholder="e.g. Fever and sore throat for 2 days"></textarea></div>
  <button class="btn">Confirm booking</button>
</form>
<?php page_end();
