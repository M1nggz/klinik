<?php
require __DIR__ . '/../includes/layout.php';
$u = require_login('doctor'); $pdo = db();
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  if (isset($_POST['del'])) { $pdo->prepare('DELETE FROM doctor_schedules WHERE id=? AND doctor_id=?')->execute([(int)$_POST['del'], $u['id']]); flash('ok', 'Schedule removed.'); }
  else {
    $w = (int)$_POST['weekday']; $s = $_POST['start'] ?? ''; $e = $_POST['end'] ?? ''; $m = (int)$_POST['mins'];
    if ($w < 1 || $w > 7 || $s >= $e || !preg_match('/^\d\d:\d\d$/', $s . '') || !in_array($m, [15, 20, 30, 45, 60])) flash('err', 'Please enter a valid day and time range.');
    else { $pdo->prepare('INSERT INTO doctor_schedules (doctor_id, weekday, start_time, end_time, slot_minutes) VALUES (?,?,?,?,?)')->execute([$u['id'], $w, $s, $e, $m]); log_act('schedule_added', "$w $s-$e"); flash('ok', 'Availability added.'); }
  }
  redirect('doctor/schedule.php');
}
$st = $pdo->prepare('SELECT * FROM doctor_schedules WHERE doctor_id=? ORDER BY weekday, start_time'); $st->execute([$u['id']]); $rows = $st->fetchAll();
page_start('My Schedule'); ?>
<div class="grid g2">
  <form class="card" method="post"><?= csrf_field() ?><h3>Set available schedule</h3><p class="muted" style="margin-bottom:14px">Example: Monday 9:00 AM – 12:00 PM.</p>
    <div class="field"><label>Day</label><select name="weekday"><?php foreach ($days as $k => $d) echo "<option value=\"$k\">$d</option>"; ?></select></div>
    <div class="row"><div class="field" style="flex:1"><label>From</label><input type="time" name="start" value="09:00" required></div><div class="field" style="flex:1"><label>To</label><input type="time" name="end" value="12:00" required></div></div>
    <div class="field"><label>Minutes per patient</label><select name="mins"><?php foreach ([15, 20, 30, 45, 60] as $m) echo "<option" . ($m == 30 ? ' selected' : '') . ">$m</option>"; ?></select></div>
    <button class="btn">Add availability</button></form>
  <div class="card"><h3>Current availability</h3><div class="tw mt"><table class="tbl"><tbody>
    <?php foreach ($rows as $r): ?><tr><td><b><?= $days[$r['weekday']] ?></b></td><td><?= fmt_time($r['start_time']) ?> – <?= fmt_time($r['end_time']) ?></td><td><?= $r['slot_minutes'] ?> min</td>
    <td><form method="post"><?= csrf_field() ?><button class="btn danger sm" name="del" value="<?= $r['id'] ?>">Remove</button></form></td></tr><?php endforeach; if (!$rows) echo '<tr><td class="muted">No availability set — patients cannot book you yet.</td></tr>'; ?></tbody></table></div></div>
</div>
<?php page_end();
