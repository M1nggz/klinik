<?php
// Doktor terima/tolak appointment melalui pautan token dalam emel (tanpa perlu login)
require __DIR__ . '/includes/layout.php';
$t = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? ''); $act = ($_GET['a'] ?? '') === 'decline' ? 'decline' : 'accept';
$st = db()->prepare('SELECT id FROM appointments WHERE token=?'); $st->execute([$t]);
$id = (int)$st->fetchColumn(); $a = $id ? appt_full($id) : null;
$done = '';
if ($a && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  if ($a['status'] === 'pending') {
    set_status($id, $_POST['do'] === 'decline' ? 'rejected' : 'confirmed', 'via email link');
    $done = $_POST['do'] === 'decline' ? 'Appointment declined. The patient has been notified.' : 'Appointment confirmed. The patient has been notified by email.';
  } else $done = 'This request was already ' . $a['status'] . '.';
}
page_start('Appointment request', 'pub'); ?>
<div class="auth"><div class="card" style="max-width:520px;width:100%;animation:pop .7s both">
<?php if (!$a): ?><h2>Link not valid</h2><p class="muted">This confirmation link is invalid or expired.</p>
<?php elseif ($done): ?><div class="ico">✅</div><h2>Done</h2><p><?= h($done) ?></p><p class="mt"><a class="btn" href="<?= url('login.php') ?>">Open dashboard</a></p>
<?php else: ?>
  <div class="ico">📅</div><h2>Appointment request</h2>
  <p class="muted">Hello <?= h($a['doctor_name']) ?>, please review this booking.</p>
  <?= appt_table($a, true) ?>
  <?php if ($a['status'] !== 'pending'): ?><p>This request is already <b><?= h($a['status']) ?></b>.</p>
  <?php else: ?><form method="post" class="row"><?= csrf_field() ?>
    <button class="btn" name="do" value="accept">✔ Accept</button><button class="btn danger" name="do" value="decline">✖ Decline</button></form><?php endif; ?>
<?php endif; ?>
</div></div>
<?php page_end('pub');
