<?php
require __DIR__ . '/../includes/layout.php';
$u = require_login('doctor'); $pdo = db();
$c = function ($q) use ($pdo, $u) { $s = $pdo->prepare($q); $s->execute([$u['id']]); return (int)$s->fetchColumn(); };
$today = $c("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND appt_date=CURDATE() AND status IN ('confirmed','arrived','pending')");
$pend = $c("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND status='pending'");
$done = $c("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND status='completed'");
$unread = $c("SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0");
$st = $pdo->prepare("SELECT a.*, p.username patient_name, p.matric_no FROM appointments a JOIN users p ON p.id=a.patient_id WHERE a.doctor_id=? AND a.appt_date=CURDATE() AND a.status<>'cancelled' ORDER BY a.appt_time");
$st->execute([$u['id']]); $rows = $st->fetchAll();
page_start('Doctor Dashboard'); ?>
<div class="hello"><h2>Good day, <?= h($u['username']) ?> 🩺</h2><p>You have <b><?= $today ?></b> appointment(s) today and <b><?= $pend ?></b> waiting for your confirmation.</p>
  <p class="mt"><a class="btn alt" href="<?= url('doctor/appointments.php') ?>">Manage appointments</a></p></div>
<div class="grid g4 mt"><?php foreach ([['Today', $today], ['Pending', $pend], ['Completed', $done], ['Unread messages', $unread]] as $k): ?>
  <div class="card kpi"><small class="muted"><?= $k[0] ?></small><b data-count="<?= $k[1] ?>">0</b></div><?php endforeach; ?></div>
<div class="card mt"><h3>Today’s schedule</h3><div class="tw mt"><table class="tbl"><tbody>
<?php foreach ($rows as $r): ?><tr><td><b><?= fmt_time($r['appt_time']) ?></b></td><td><?= h($r['patient_name']) ?> <small class="muted">(<?= h($r['matric_no']) ?>)</small></td><td><?= h($r['reason'] ?: '-') ?></td><td><span class="badge b-<?= $r['status'] ?>"><?= $r['status'] ?></span></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td class="muted">Nothing scheduled for today.</td></tr>'; ?></tbody></table></div></div>
<?php page_end();
