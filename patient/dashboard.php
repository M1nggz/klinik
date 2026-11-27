<?php
require __DIR__ . '/../includes/layout.php';
$u = require_login('patient'); $pdo = db();
$n = $pdo->prepare("SELECT id FROM appointments WHERE patient_id=? AND status IN ('pending','confirmed','arrived') AND TIMESTAMP(appt_date,appt_time)>=NOW() ORDER BY appt_date, appt_time LIMIT 1");
$n->execute([$u['id']]); $next = ($id = $n->fetchColumn()) ? appt_full((int)$id) : null;
$cnt = fn($q) => (function () use ($pdo, $q, $u) { $s = $pdo->prepare($q); $s->execute([$u['id']]); return (int)$s->fetchColumn(); })();
$up = $cnt("SELECT COUNT(*) FROM appointments WHERE patient_id=? AND status IN ('pending','confirmed') AND TIMESTAMP(appt_date,appt_time)>=NOW()");
$done = $cnt("SELECT COUNT(*) FROM appointments WHERE patient_id=? AND status='completed'");
$recs = $cnt("SELECT COUNT(*) FROM medical_records WHERE patient_id=?");
$unread = $cnt("SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0");
page_start('Patient Dashboard'); ?>
<div class="hello"><h2>Hello, <?= h($u['username']) ?> 👋</h2>
  <p><?php if ($next): ?>Your next appointment: <b><?= fmt_date($next['appt_date']) ?> at <?= fmt_time($next['appt_time']) ?></b> with <?= h($next['doctor_name']) ?> (<?= h($next['status']) ?>).
  <?php else: ?>You have no upcoming appointments. Let’s book one!<?php endif; ?></p>
  <p class="mt"><a class="btn alt" href="<?= url('patient/book.php') ?>">Book appointment</a></p></div>
<div class="grid g4 mt">
  <?php foreach ([['Upcoming', $up], ['Completed', $done], ['Records', $recs], ['Unread messages', $unread]] as $k): ?>
  <div class="card kpi"><small class="muted"><?= $k[0] ?></small><b data-count="<?= $k[1] ?>">0</b></div><?php endforeach; ?>
</div>
<h3 class="mt">Quick access</h3>
<div class="grid g4" style="margin-top:12px">
  <?php foreach ([['patient/appointments.php','🗓️','Appointments'],['patient/doctors.php','🩺','Doctors'],['patient/records.php','📋','Medical Records'],['messages.php','💬','Messages'],['profile.php','👤','Profile']] as $q): ?>
  <a class="card hv" style="text-decoration:none;color:inherit;text-align:center" href="<?= url($q[0]) ?>"><div class="ico"><?= $q[1] ?></div><h3><?= $q[2] ?></h3></a><?php endforeach; ?>
</div>
<h3 class="mt">Health tips for you</h3>
<div class="grid g3" style="margin-top:12px"><?php foreach (array_slice(health_tips(), 0, 3) as $t): ?>
  <div class="card hv"><div class="ico"><?= $t[0] ?></div><h3><?= h($t[1]) ?></h3><p class="muted"><?= h($t[2]) ?></p></div><?php endforeach; ?></div>
<?php page_end();
