<?php
require __DIR__ . '/../includes/layout.php';
$u = require_login('patient');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $id = (int)$_POST['id'];
  $a = appt_full($id);
  if ($a && (int)$a['patient_id'] === (int)$u['id'] && in_array($a['status'], ['pending', 'confirmed'], true)) { set_status($id, 'cancelled', 'patient'); flash('ok', 'Appointment cancelled.'); }
  redirect('patient/appointments.php');
}
$nw = db()->prepare('SELECT id FROM appointments WHERE patient_id=? AND patient_seen=0'); $nw->execute([$u['id']]);
$new = array_flip(array_column($nw->fetchAll(), 'id'));
db()->prepare('UPDATE appointments SET patient_seen=1 WHERE patient_id=? AND patient_seen=0')->execute([$u['id']]);
$st = db()->prepare("SELECT a.*, d.username doctor_name, d.specialty FROM appointments a JOIN users d ON d.id=a.doctor_id WHERE a.patient_id=? ORDER BY a.appt_date DESC, a.appt_time DESC");
$st->execute([$u['id']]); $rows = $st->fetchAll();
page_start('My Appointments'); ?>
<div class="card"><div class="row sp"><h3>All appointments</h3><a class="btn sm" href="<?= url('patient/book.php') ?>">+ New</a></div>
<div class="tw mt"><table class="tbl"><thead><tr><th>Date</th><th>Time</th><th>Doctor</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): $future = strtotime($r['appt_date'] . ' ' . $r['appt_time']) > time(); ?>
<tr><td><?= isset($new[$r['id']]) ? '<span class="badge b-new">New update</span><br>' : '' ?><?= fmt_date($r['appt_date']) ?></td><td><?= fmt_time($r['appt_time']) ?></td><td><b><?= h($r['doctor_name']) ?></b><br><small class="muted"><?= h($r['specialty']) ?></small></td><td><?= $r['followup_of'] ? '<span class="badge b-arrived">Follow-up</span> ' : '' ?><?= h($r['reason'] ?: '-') ?></td>
<td><span class="badge b-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
<td><?php if ($future && in_array($r['status'], ['pending', 'confirmed'])): ?><form method="post" data-confirm="Cancel this appointment?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn danger sm">Cancel</button></form><?php endif; ?></td></tr>
<?php if ($r['status'] === 'completed'): ?><tr class="crow"><td colspan="6"><?= consult_block((int)$r['id']) ?></td></tr><?php endif; ?>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="muted">No appointments yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php page_end();
