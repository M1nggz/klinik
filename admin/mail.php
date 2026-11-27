<?php
require __DIR__ . '/../includes/layout.php';
$u = require_login('admin'); $pdo = db(); admin_seen_mark('fail');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  if (isset($_POST['add'])) {
    $name = trim($_POST['display_name']); $email = trim($_POST['email']); $pw = str_replace(' ', '', $_POST['app_password'] ?? '');
    if ($name === '' || $pw === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('err', 'Please fill in all fields with a valid email.');
    else try { $pdo->prepare('INSERT INTO sender_accounts (display_name, email, app_password_enc) VALUES (?,?,?)')->execute([$name, $email, encrypt_pw($pw)]); log_act('sender_added', $email); flash('ok', 'Sender saved.'); }
    catch (PDOException $e) { flash('err', 'That email already exists.'); }
  }
  if (isset($_POST['delete'])) { $pdo->prepare('DELETE FROM sender_accounts WHERE id=?')->execute([(int)$_POST['id']]); log_act('sender_deleted'); flash('ok', 'Sender removed.'); }
  if (isset($_POST['test'])) {
    $ok = send_mail($u['email'], $u['username'], 'Test email from ' . SITE_NAME, mail_layout('It works! 🎉', '<p>Your email system is configured correctly.</p>'));
    flash($ok ? 'ok' : 'err', $ok ? 'Test email sent to ' . $u['email'] : 'Failed: ' . $GLOBALS['MAIL_ERR']);
  }
  redirect('admin/mail.php');
}
$accs = $pdo->query('SELECT id, display_name, email FROM sender_accounts ORDER BY id')->fetchAll();
$log = $pdo->query("SELECT * FROM activity_log WHERE action IN ('email_sent','email_failed') ORDER BY id DESC LIMIT 15")->fetchAll();
page_start('Email Settings'); ?>
<div class="grid g2">
  <div class="card"><h3>Sender accounts</h3><p class="muted">The <b>first</b> account is used for all notifications.</p>
    <table class="tbl mt"><tbody><?php foreach ($accs as $a): ?><tr><td><b><?= h($a['display_name']) ?></b><br><small class="muted"><?= h($a['email']) ?></small></td>
      <td><form method="post" data-confirm="Remove this sender?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn danger sm" name="delete" value="1">Remove</button></form></td></tr><?php endforeach; ?></tbody></table>
    <?php if ($accs): ?><form method="post" class="mt"><?= csrf_field() ?><button class="btn alt sm" name="test" value="1">Send test email to me</button></form><?php endif; ?></div>
  <form class="card" method="post"><?= csrf_field() ?><h3>Add Gmail sender</h3>
    <div class="field mt"><label>Display name</label><input name="display_name" required placeholder="CampusCare Clinic"></div>
    <div class="field"><label>Gmail address</label><input type="email" name="email" required></div>
    <div class="field"><label>Gmail App Password (16 characters)</label><input type="password" name="app_password" required><small class="muted">Use an App Password, not your normal Gmail password.</small></div>
    <button class="btn" name="add" value="1">Save sender</button></form>
</div>
<div class="card mt"><h3>Recent email log</h3><div class="tw mt"><table class="tbl"><tbody><?php foreach ($log as $l): ?><tr><td><small><?= date('d M H:i', strtotime($l['created_at'])) ?></small></td><td><span class="badge <?= $l['action'] === 'email_sent' ? 'b-confirmed' : 'b-cancelled' ?>"><?= $l['action'] ?></span></td><td><?= h($l['details']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php page_end();
