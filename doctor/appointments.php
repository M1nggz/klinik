<?php
require __DIR__ . '/../includes/layout.php';
$u = require_login('doctor'); $pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $id = (int)$_POST['id']; $do = $_POST['do'] ?? ''; $a = appt_full($id);
  if ($a && (int)$a['doctor_id'] === (int)$u['id']) {
    $map = ['accept' => 'confirmed', 'decline' => 'rejected', 'arrived' => 'arrived', 'missed' => 'missed'];
    if (isset($map[$do])) { set_status($id, $map[$do], 'by doctor'); flash('ok', 'Appointment updated.'); }
    $fd = trim($_POST['fu_date'] ?? ''); $ft = trim($_POST['fu_time'] ?? '') ?: '09:00'; $fn = trim(mb_substr($_POST['fu_note'] ?? '', 0, 150));
    if ($do === 'complete' && trim($_POST['diagnosis'] ?? '') !== '') {
      $pdo->prepare('INSERT INTO medical_records (patient_id, doctor_id, appointment_id, diagnosis, notes, patient_seen) VALUES (?,?,?,?,?,0)')
          ->execute([$a['patient_id'], $u['id'], $id, trim($_POST['diagnosis']), trim($_POST['notes'] ?? '')]);
      set_status($id, 'completed', 'record added');
      $msg = 'Consultation completed and record saved.'; $err = '';
      if ($fd !== '') { $err = schedule_followup($a, $fd, $ft, $fn); $msg .= $err ? ' Follow-up NOT scheduled: ' . $err : ' Follow-up review scheduled and the patient was emailed.'; }
      flash($err ? 'err' : 'ok', $msg);
    }
    if ($do === 'followup' && $a['status'] === 'completed') {
      $err = schedule_followup($a, $fd, $ft, $fn);
      flash($err ? 'err' : 'ok', $err ?: 'Follow-up review scheduled and the patient was emailed.');
    }
  }
  redirect('doctor/appointments.php?f=' . urlencode($_GET['f'] ?? 'upcoming'));
}
$pdo->prepare('UPDATE appointments SET doctor_seen=1 WHERE doctor_id=?')->execute([$u['id']]);
$f = $_GET['f'] ?? 'upcoming';
$where = ['upcoming' => "a.appt_date>=CURDATE() AND a.status IN ('pending','confirmed','arrived')", 'pending' => "a.status='pending'", 'completed' => "a.status='completed'", 'all' => '1=1'][$f] ?? '1=1';
$order = $f === 'completed' ? 'DESC' : 'ASC';
$st = $pdo->prepare("SELECT a.*, p.username patient_name, p.matric_no, p.email patient_email FROM appointments a JOIN users p ON p.id=a.patient_id WHERE a.doctor_id=? AND $where ORDER BY a.appt_date $order, a.appt_time $order LIMIT 200");
$st->execute([$u['id']]); $rows = $st->fetchAll();
page_start('Appointments'); ?>
<div class="row" style="margin-bottom:16px"><?php foreach (['upcoming' => 'Upcoming', 'pending' => 'Needs confirmation', 'completed' => 'Completed', 'all' => 'All'] as $k => $l): ?><a class="btn sm <?= $f === $k ? '' : 'alt' ?>" href="?f=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="card"><div class="tw"><table class="tbl"><thead><tr><th>When</th><th>Patient</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
<td><b><?= fmt_date($r['appt_date']) ?></b><br><?= fmt_time($r['appt_time']) ?></td>
<td><?= h($r['patient_name']) ?><br><small class="muted"><?= h($r['matric_no']) ?></small></td>
<td><?= $r['followup_of'] ? '<span class="badge b-arrived">Follow-up</span> ' : '' ?><?= h($r['reason'] ?: '-') ?></td>
<td><span class="badge b-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
<td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
<?php if ($r['status'] === 'pending'): ?><button class="btn sm" name="do" value="accept">Accept</button><button class="btn danger sm" name="do" value="decline">Decline</button>
<?php elseif ($r['status'] === 'confirmed'): ?><button class="btn sm" name="do" value="arrived">Patient arrived</button><button class="btn alt sm" name="do" value="missed">No-show</button><?php endif; ?></form>
<?php if (in_array($r['status'], ['confirmed', 'arrived'])): ?><details class="more" style="margin-top:8px"><summary>Complete consultation</summary>
<form method="post" style="margin-top:8px;min-width:260px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="do" value="complete">
<div class="field"><input name="diagnosis" placeholder="Diagnosis" required></div><div class="field"><textarea name="notes" placeholder="Notes / prescription"></textarea></div>
<div class="field"><label>🔁 Follow-up review <small class="muted">(optional)</small></label><div class="row" style="flex-wrap:nowrap"><input type="date" name="fu_date" min="<?= date('Y-m-d') ?>"><input type="time" name="fu_time" value="09:00"></div></div>
<div class="field"><input name="fu_note" placeholder="Note for patient (shown in email)" maxlength="150"></div>
<button class="btn sm">Save record</button></form></details><?php endif; ?>
<?php if ($r['status'] === 'completed'): ?><details class="more" style="margin-top:8px"><summary>Add follow-up review</summary>
<form method="post" style="margin-top:8px;min-width:260px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="do" value="followup">
<div class="field"><div class="row" style="flex-wrap:nowrap"><input type="date" name="fu_date" min="<?= date('Y-m-d') ?>" required><input type="time" name="fu_time" value="09:00" required></div></div>
<div class="field"><input name="fu_note" placeholder="Note for patient (shown in email)" maxlength="150"></div>
<button class="btn sm">Schedule &amp; email patient</button></form></details><?php endif; ?></td></tr>
<?php if ($r['status'] === 'completed'): ?><tr class="crow"><td colspan="5"><?= consult_block((int)$r['id']) ?></td></tr><?php endif; ?>
<?php endforeach; if (!$rows) echo '<tr><td colspan="5" class="muted">No appointments found.</td></tr>'; ?></tbody></table></div></div>
<?php page_end();
