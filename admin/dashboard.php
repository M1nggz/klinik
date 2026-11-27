<?php
require __DIR__ . '/../includes/layout.php';
$u = require_login('admin'); $pdo = db();
$c = fn($q) => (int)$pdo->query($q)->fetchColumn();
$k = [['Patients', $c("SELECT COUNT(*) FROM users WHERE role='patient'")], ['Doctors', $c("SELECT COUNT(*) FROM users WHERE role='doctor'")],
      ['Appointments today', $c("SELECT COUNT(*) FROM appointments WHERE appt_date=CURDATE()")], ['Pending', $c("SELECT COUNT(*) FROM appointments WHERE status='pending'")],
      ['Emails sent (24h)', $c("SELECT COUNT(*) FROM activity_log WHERE action='email_sent' AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)")], ['Email failures (24h)', $c("SELECT COUNT(*) FROM activity_log WHERE action='email_failed' AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)")]];
$by = $pdo->query("SELECT status, COUNT(*) n FROM appointments GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR); $tot = max(1, array_sum($by));
$log = $pdo->query("SELECT l.*, u.username FROM activity_log l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 10")->fetchAll();
page_start('Admin Dashboard'); ?>
<?php if (!$c("SELECT COUNT(*) FROM sender_accounts")): ?><div class="card" style="border-left:6px solid #d0645a;margin-bottom:20px"><b>⚠ No email sender configured.</b> Notifications will not be delivered. <a href="<?= url('admin/mail.php') ?>">Add a Gmail sender →</a></div><?php endif; ?>
<div class="grid g3"><?php foreach ($k as $x): ?><div class="card kpi hv"><small class="muted"><?= $x[0] ?></small><b data-count="<?= $x[1] ?>">0</b></div><?php endforeach; ?></div>
<div class="grid g2 mt">
  <div class="card"><h3>Appointments by status</h3><div class="mt" style="display:grid;gap:12px"><?php foreach ($by as $s => $n): ?>
    <div><div class="row sp"><span class="badge b-<?= $s ?>"><?= $s ?></span><b><?= $n ?></b></div><div class="bar"><i data-w="<?= round($n / $tot * 100) ?>" style="width:0"></i></div></div><?php endforeach; if (!$by) echo '<p class="muted">No data yet.</p>'; ?></div></div>
  <div class="card"><div class="row sp"><h3>Recent activity</h3><a href="<?= url('admin/activity.php') ?>">View all</a></div><div class="tw mt"><table class="tbl"><tbody>
    <?php foreach ($log as $l): ?><tr><td><small class="muted"><?= date('d M H:i', strtotime($l['created_at'])) ?></small></td><td><b><?= h($l['action']) ?></b><br><small class="muted"><?= h($l['username'] ?? 'system') ?> · <?= h(mb_substr($l['details'], 0, 50)) ?></small></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php page_end();
