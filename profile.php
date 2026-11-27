<?php
require __DIR__ . '/includes/layout.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $name = trim($_POST['username'] ?? ''); $email = trim($_POST['email'] ?? ''); $phone = trim($_POST['phone'] ?? ''); $pw = $_POST['password'] ?? '';
  $dup = db()->prepare('SELECT 1 FROM users WHERE email=? AND id<>?'); $dup->execute([$email, $u['id']]);
  if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('err', 'Please enter a valid name and email.');
  elseif ($dup->fetch()) flash('err', 'That email is used by another account.');
  elseif ($pw !== '' && strlen($pw) < 8) flash('err', 'New password must be at least 8 characters.');
  else {
    db()->prepare('UPDATE users SET username=?, email=?, phone=?, bio=? WHERE id=?')->execute([$name, $email, $phone, $u['role'] === 'doctor' ? trim($_POST['bio'] ?? '') : $u['bio'], $u['id']]);
    if ($pw !== '') db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
    log_act('profile_updated'); flash('ok', 'Profile updated.'); redirect('profile.php');
  }
}
page_start('Profile'); ?>
<form class="card" method="post" style="max-width:640px"><?= csrf_field() ?>
  <div class="doc-av"><?= h(mb_strtoupper(mb_substr($u['username'], 0, 1))) ?></div>
  <p class="muted" style="margin-bottom:16px">ID / Matric: <b><?= h($u['matric_no']) ?></b> · <?= ucfirst($u['role']) ?></p>
  <div class="field"><label>Username</label><input name="username" required value="<?= h($u['username']) ?>"></div>
  <div class="field"><label>Email (all notifications are sent here)</label><input type="email" name="email" required value="<?= h($u['email']) ?>"></div>
  <div class="field"><label>Phone</label><input name="phone" value="<?= h($u['phone']) ?>"></div>
  <?php if ($u['role'] === 'doctor'): ?><div class="field"><label>About me</label><textarea name="bio"><?= h($u['bio']) ?></textarea></div><?php endif; ?>
  <div class="field"><label>New password <small class="muted">(leave blank to keep current)</small></label><div class="pw"><input type="password" name="password" minlength="8"><button type="button" tabindex="-1">👁</button></div></div>
  <div class="row"><button class="btn">Save changes</button><a class="btn alt" href="<?= url('logout.php') ?>">Logout</a></div>
</form>
<?php page_end();
