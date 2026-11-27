<?php
require __DIR__ . '/includes/layout.php';
if (me()) redirect(home_for(me()['role']));
$err = ''; $v = ['username' => '', 'email' => '', 'matric_no' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $v = ['username' => trim($_POST['username'] ?? ''), 'email' => trim($_POST['email'] ?? ''), 'matric_no' => strtoupper(trim($_POST['matric_no'] ?? ''))];
  $pw = $_POST['password'] ?? '';
  if (mb_strlen($v['username']) < 2 || mb_strlen($v['username']) > 60) $err = 'Username must be 2–60 characters.';
  elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err = 'Please enter a valid email address.';
  elseif (!preg_match('/^[A-Z0-9]{5,20}$/', $v['matric_no'])) $err = 'Matric number must be 5–20 letters/numbers.';
  elseif (strlen($pw) < 8) $err = 'Password must be at least 8 characters.';
  else {
    $x = db()->prepare('SELECT 1 FROM users WHERE matric_no=? OR email=?'); $x->execute([$v['matric_no'], $v['email']]);
    if ($x->fetch()) $err = 'That matric number or email is already registered.';
    else {
      db()->prepare("INSERT INTO users (role, matric_no, username, email, password_hash) VALUES ('patient',?,?,?,?)")
          ->execute([$v['matric_no'], $v['username'], $v['email'], password_hash($pw, PASSWORD_DEFAULT)]);
      $uid = (int)db()->lastInsertId(); log_act('register', $v['matric_no'], $uid);
      send_mail($v['email'], $v['username'], 'Welcome to ' . SITE_NAME,
        mail_layout('Welcome, ' . $v['username'] . '! 🎉', '<p>Your account is ready. Log in with your matric number <b>' . h($v['matric_no']) . '</b> to book your first appointment. We will email you about every booking and reminders.</p>', 'Login now', BASE_URL . '/login.php'));
      flash('ok', 'Account created! Please log in.'); redirect('login.php');
    }
  }
}
page_start('Register', 'pub'); ?>
<div class="auth"><div class="card auth-card">
  <div class="auth-side"><a class="brand" style="color:#fff" href="<?= url() ?>"><span class="logo" style="background:rgba(255,255,255,.25)">✚</span> <?= h(SITE_NAME) ?></a>
    <h2>Join us today ✨</h2><p>One account for appointments, reminders and your medical history. Use a real email — all notifications are sent there.</p></div>
  <form class="auth-form" method="post"><?= csrf_field() ?>
    <h2>Create account</h2><p class="muted" style="margin-bottom:18px">Patients only. Doctors are added by the admin.</p>
    <?php if ($err): ?><div class="toast err" style="margin-bottom:16px;animation:none"><?= h($err) ?></div><?php endif; ?>
    <div class="field"><label>Username</label><input name="username" required value="<?= h($v['username']) ?>"></div>
    <div class="field"><label>Email</label><input type="email" name="email" required value="<?= h($v['email']) ?>"></div>
    <div class="field"><label>Matric number</label><input name="matric_no" required placeholder="e.g. A21CS0001" value="<?= h($v['matric_no']) ?>"></div>
    <div class="field"><label>Password (min. 8 characters)</label><div class="pw"><input type="password" name="password" required minlength="8"><button type="button" tabindex="-1">👁</button></div></div>
    <button class="btn" style="width:100%">Register</button>
    <p class="muted mt" style="text-align:center">Already registered? <a href="<?= url('login.php') ?>">Login</a></p>
  </form>
</div></div>
<?php page_end('pub');
