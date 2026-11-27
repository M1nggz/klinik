<?php
require __DIR__ . '/../includes/layout.php';
require_login('patient');
$docs = db()->query("SELECT id, username, specialty, bio FROM users WHERE role='doctor' AND status='active' ORDER BY username")->fetchAll();
$days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
page_start('Doctors'); ?>
<div class="grid g3"><?php foreach ($docs as $d):
  $s = db()->prepare('SELECT weekday, start_time, end_time FROM doctor_schedules WHERE doctor_id=? ORDER BY weekday, start_time'); $s->execute([$d['id']]); ?>
  <div class="card hv"><div class="doc-av"><?= h(mb_substr(preg_replace('/^Dr\.?\s*/i', '', $d['username']), 0, 1)) ?></div>
    <h3><?= h($d['username']) ?></h3><span class="badge b-completed"><?= h($d['specialty']) ?></span>
    <p class="muted" style="margin:10px 0"><?= h($d['bio']) ?></p>
    <details class="more"><summary>Weekly schedule</summary><div style="margin-top:8px;font-size:14px">
      <?php foreach ($s->fetchAll() as $r) echo '<div>' . $days[$r['weekday']] . ' · ' . fmt_time($r['start_time']) . ' – ' . fmt_time($r['end_time']) . '</div>'; ?></div></details>
    <div class="row mt"><a class="btn sm" href="<?= url('patient/book.php?doctor=' . $d['id']) ?>">Book</a><a class="btn alt sm" href="<?= url('messages.php?with=' . $d['id']) ?>">Message</a></div></div>
<?php endforeach; ?></div>
<?php page_end();
