<?php
require __DIR__ . '/../includes/layout.php';
require_login('admin'); $pdo = db();
$a = trim($_GET['a'] ?? ''); $q = trim($_GET['q'] ?? '');
$st = $pdo->prepare("SELECT l.*, u.username, u.role FROM activity_log l LEFT JOIN users u ON u.id=l.user_id WHERE (?='' OR l.action=?) AND (l.details LIKE ? OR u.username LIKE ? OR l.ip LIKE ?) ORDER BY l.id DESC LIMIT 300");
$st->execute([$a, $a, "%$q%", "%$q%", "%$q%"]); $rows = $st->fetchAll();
$acts = $pdo->query('SELECT DISTINCT action FROM activity_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
page_start('Activity Log'); ?>
<div class="card"><form class="row" style="margin-bottom:14px"><input name="q" placeholder="Search user, details or IP" value="<?= h($q) ?>" style="max-width:280px"><select name="a" style="max-width:230px"><option value="">All actions</option><?php foreach ($acts as $x) echo '<option' . ($a === $x ? ' selected' : '') . '>' . h($x) . '</option>'; ?></select><button class="btn sm">Filter</button></form>
<div class="tw"><table class="tbl"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><small><?= date('d M Y H:i:s', strtotime($r['created_at'])) ?></small></td><td><?= h($r['username'] ?? 'system') ?> <small class="muted"><?= h($r['role'] ?? '') ?></small></td>
<td><span class="badge <?= str_contains($r['action'], 'fail') ? 'b-cancelled' : 'b-completed' ?>"><?= h($r['action']) ?></span></td><td><?= h($r['details']) ?></td><td><small><?= h($r['ip']) ?></small></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php page_end();
