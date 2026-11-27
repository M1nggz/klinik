<?php
require __DIR__ . '/../includes/layout.php';
require_login('admin'); $pdo = db();
$role = ($_GET['role'] ?? '') === 'doctor' ? 'doctor' : 'patient'; $R = ucfirst($role); if ($role === 'patient') admin_seen_mark('patient');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $do = $_POST['do'] ?? ''; $id = (int)($_POST['id'] ?? 0);
  try {
    if ($do === 'save') {
      $f = [strtoupper(trim($_POST['matric_no'])), trim($_POST['username']), trim($_POST['email']), trim($_POST['phone'] ?? ''), trim($_POST['specialty'] ?? '') ?: null, trim($_POST['bio'] ?? '') ?: null];
      if (!filter_var($f[2], FILTER_VALIDATE_EMAIL) || $f[0] === '' || $f[1] === '') throw new Exception('Please fill ID, name and a valid email.');
      $pw = $_POST['password'] ?? '';
      if ($id) {
        $pdo->prepare('UPDATE users SET matric_no=?, username=?, email=?, phone=?, specialty=?, bio=? WHERE id=? AND role=?')->execute([...$f, $id, $role]);
        if ($pw !== '') $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
      } else {
        if (strlen($pw) < 8) throw new Exception('Password must be at least 8 characters.');
        $pdo->prepare('INSERT INTO users (matric_no, username, email, phone, specialty, bio, role, password_hash) VALUES (?,?,?,?,?,?,?,?)')->execute([...$f, $role, password_hash($pw, PASSWORD_DEFAULT)]);
      }
      log_act('admin_user_saved', "$role {$f[0]}"); flash('ok', "$R saved.");
    } elseif ($do === 'toggle') {
      $pdo->prepare("UPDATE users SET status=IF(status='active','disabled','active') WHERE id=? AND role<>'admin'")->execute([$id]); log_act('admin_user_toggled', "#$id"); flash('ok', 'Status changed.');
    } elseif ($do === 'delete') {
      $pdo->prepare("DELETE FROM users WHERE id=? AND role=?")->execute([$id, $role]); log_act('admin_user_deleted', "#$id"); flash('ok', "$R deleted.");
    }
  } catch (PDOException $e) { flash('err', 'That ID or email already exists.'); } catch (Exception $e) { flash('err', $e->getMessage()); }
  redirect('admin/users.php?role=' . $role);
}
$q = trim($_GET['q'] ?? ''); $edit = null;
if (!empty($_GET['edit'])) { $s = $pdo->prepare('SELECT * FROM users WHERE id=? AND role=?'); $s->execute([(int)$_GET['edit'], $role]); $edit = $s->fetch() ?: null; }
$s = $pdo->prepare("SELECT * FROM users WHERE role=? AND (username LIKE ? OR matric_no LIKE ? OR email LIKE ?) ORDER BY id DESC"); $s->execute([$role, "%$q%", "%$q%", "%$q%"]); $rows = $s->fetchAll();
page_start($R . 's'); ?>
<details class="card" <?= $edit ? 'open' : '' ?> style="margin-bottom:20px"><summary class="btn sm" style="display:inline-flex"><?= $edit ? 'Edit ' . $R : '+ Add ' . $R ?></summary>
<form method="post" class="mt"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
<div class="grid g3">
  <div class="field"><label><?= $role === 'doctor' ? 'Staff ID (used to login)' : 'Matric number' ?></label><input name="matric_no" required value="<?= h($edit['matric_no'] ?? '') ?>"></div>
  <div class="field"><label>Name</label><input name="username" required value="<?= h($edit['username'] ?? '') ?>"></div>
  <div class="field"><label>Email</label><input type="email" name="email" required value="<?= h($edit['email'] ?? '') ?>"></div>
  <div class="field"><label>Phone</label><input name="phone" value="<?= h($edit['phone'] ?? '') ?>"></div>
  <?php if ($role === 'doctor'): ?><div class="field"><label>Specialty</label><input name="specialty" value="<?= h($edit['specialty'] ?? '') ?>"></div><?php endif; ?>
  <div class="field"><label>Password <?= $edit ? '(blank = unchanged)' : '' ?></label><input type="text" name="password" <?= $edit ? '' : 'required' ?> minlength="8"></div>
</div>
<?php if ($role === 'doctor'): ?><div class="field"><label>Bio</label><textarea name="bio"><?= h($edit['bio'] ?? '') ?></textarea></div><?php endif; ?>
<button class="btn">Save</button> <?php if ($edit): ?><a class="btn alt" href="?role=<?= $role ?>">Cancel</a><?php endif; ?></form></details>
<div class="card"><form class="row" style="margin-bottom:14px"><input type="hidden" name="role" value="<?= $role ?>"><input name="q" placeholder="Search name, ID or email" value="<?= h($q) ?>" style="max-width:320px"><button class="btn sm">Search</button></form>
<div class="tw"><table class="tbl"><thead><tr><th>ID</th><th>Name</th><th>Email</th><?php if ($role === 'doctor') echo '<th>Specialty</th>'; ?><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><b><?= h($r['matric_no']) ?></b></td><td><?= h($r['username']) ?></td><td><?= h($r['email']) ?></td><?php if ($role === 'doctor') echo '<td>' . h($r['specialty']) . '</td>'; ?>
<td><span class="badge <?= $r['status'] === 'active' ? 'b-confirmed' : 'b-cancelled' ?>"><?= $r['status'] ?></span></td>
<td><div class="row"><a class="btn alt sm" href="?role=<?= $role ?>&edit=<?= $r['id'] ?>">Edit</a>
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn alt sm" name="do" value="toggle"><?= $r['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form>
<form method="post" data-confirm="Delete this <?= $role ?> and all their data?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn danger sm" name="do" value="delete">Delete</button></form></div></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="6" class="muted">None found.</td></tr>'; ?></tbody></table></div></div>
<?php page_end();
