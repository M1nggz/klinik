<?php
require __DIR__ . '/../includes/layout.php';
require_login('admin'); $pdo = db(); admin_seen_mark('appt');
$sts = ['pending', 'confirmed', 'arrived', 'completed', 'rejected', 'cancelled', 'missed'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $id = (int)$_POST['id']; $a = appt_full($id);
  if ($a && ($_POST['do'] ?? '') === 'delete') { $pdo->prepare('DELETE FROM appointments WHERE id=?')->execute([$id]); log_act('admin_appt_deleted', "#$id"); flash('ok', 'Appointment deleted.'); }
  elseif ($a) {
    $d = $_POST['date'] ?? ''; $t = $_POST['time'] ?? ''; $s = $_POST['status'] ?? $a['status'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && preg_match('/^\d\d:\d\d/', $t)) $pdo->prepare('UPDATE appointments SET appt_date=?, appt_time=?, reminder_sent=0, late_sent=0 WHERE id=?')->execute([$d, $t, $id]);
    if (in_array($s, $sts) && $s !== $a['status']) set_status($id, $s, 'by admin');
    log_act('admin_appt_edited', "#$id"); flash('ok', 'Appointment updated.');
  }
  redirect('admin/appointments.php');
}
$fs = $_GET['s'] ?? ''; $q = trim($_GET['q'] ?? '');
$st = $pdo->prepare("SELECT a.*, p.username patient_name, p.matric_no, d.username doctor_name FROM appointments a JOIN users p ON p.id=a.patient_id JOIN users d ON d.id=a.doctor_id
  WHERE (?='' OR a.status=?) AND (p.username LIKE ? OR p.matric_no LIKE ? OR d.username LIKE ?) ORDER BY a.appt_date DESC, a.appt_time DESC LIMIT 200");
$st->execute([$fs, $fs, "%$q%", "%$q%", "%$q%"]); $rows = $st->fetchAll();
page_start('Appointments'); ?>
<div class="card"><form class="row" style="margin-bottom:14px"><input name="q" placeholder="Patient, matric or doctor" value="<?= h($q) ?>" style="max-width:280px"><select name="s" style="max-width:180px"><option value="">All statuses</option><?php foreach ($sts as $s) echo '<option' . ($fs === $s ? ' selected' : '') . ">$s</option>"; ?></select><button class="btn sm">Filter</button></form>
<div class="tw"><table class="tbl"><thead><tr><th>#</th><th>Patient</th><th>Doctor</th><th>Edit date / time / status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= $r['id'] ?></td><td><?= h($r['patient_name']) ?><br><small class="muted"><?= h($r['matric_no']) ?></small></td><td><?= h($r['doctor_name']) ?></td>
<td><form method="post" class="row" style="flex-wrap:nowrap"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
<input type="date" name="date" value="<?= $r['appt_date'] ?>" style="width:150px"><input type="time" name="time" value="<?= substr($r['appt_time'], 0, 5) ?>" style="width:120px">
<select name="status" style="width:130px"><?php foreach ($sts as $s) echo '<option' . ($r['status'] === $s ? ' selected' : '') . ">$s</option>"; ?></select><button class="btn sm">Save</button></form></td>
<td><form method="post" data-confirm="Delete this appointment?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn danger sm" name="do" value="delete">Delete</button></form></td></tr>
<?php if ($r['status'] === 'completed'): ?><tr class="crow"><td colspan="5"><?= consult_block((int)$r['id']) ?></td></tr><?php endif; ?>
<?php endforeach; if (!$rows) echo '<tr><td colspan="5" class="muted">No appointments.</td></tr>'; ?></tbody></table></div></div>
<?php page_end();
