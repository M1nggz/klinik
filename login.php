<?php
require __DIR__ . '/includes/layout.php';
if (me()) redirect(home_for(me()['role']));
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $id = strtoupper(trim($_POST['matric_no'] ?? '')); $pw = $_POST['password'] ?? '';
  $c = db()->prepare("SELECT COUNT(*) FROM activity_log WHERE action='login_failed' AND ip=? AND created_at>DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
  $c->execute([ip()]);
  if ($c->fetchColumn() >= 8) {
    $err = 'Too many failed attempts. Please wait 15 minutes.';
  } else {
    $st = db()->prepare("SELECT * FROM users WHERE matric_no=? AND status='active'"); $st->execute([$id]);
    $u = $st->fetch();
    if ($u && password_verify($pw, $u['password_hash'])) {
      login_user($u, !empty($_POST['remember']));
      redirect(home_for($u['role']));
    }
    log_act('login_failed', $id); $err = 'Incorrect ID / matric number or password.';
  }
}
page_start('Login', 'pub'); ?>
<div class="auth"><div class="card auth-card">
  <div class="auth-side"><a class="brand" style="color:#fff" href="<?= url() ?>"><span class="logo" style="background:rgba(255,255,255,.25)">✚</span> <?= h(SITE_NAME) ?></a>
    <h2>Welcome back 👋</h2><p>Log in to book appointments, view records and message your doctor.</p></div>
  <form class="auth-form" method="post"><?= csrf_field() ?>
    <h2>Login</h2><p class="muted" style="margin-bottom:22px">Patients, doctors and admins log in the same way.</p>
    <?php if ($err): ?><div class="toast err" style="margin-bottom:16px;animation:none"><?= h($err) ?></div><?php endif; ?>
    <div class="field"><label>Matric number / Staff ID</label><input name="matric_no" required autofocus placeholder="e.g. A21CS0001" value="<?= h($_POST['matric_no'] ?? '') ?>"></div>
    <div class="field"><label>Password</label><div class="pw"><input type="password" name="password" required><button type="button" tabindex="-1">👁</button></div></div>
    <div class="field"><label class="check"><input type="checkbox" name="remember" value="1"> Remember me for 30 days</label></div>
    <button class="btn" style="width:100%">Login</button>
    <p class="muted mt" style="text-align:center">New patient? <a href="<?= url('register.php') ?>">Create an account</a></p>
  </form>
</div></div>
<?php page_end('pub');
