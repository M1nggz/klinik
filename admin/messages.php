<?php
require __DIR__ . '/../includes/layout.php';
require_login('admin'); $pdo = db(); admin_seen_mark('msg');
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check(); $pdo->prepare('DELETE FROM messages WHERE id=?')->execute([(int)$_POST['id']]); log_act('admin_message_deleted', '#' . (int)$_POST['id']); flash('ok', 'Message deleted.'); redirect('admin/messages.php'); }
$rows = $pdo->query("SELECT m.*, s.username sname, s.role srole, r.username rname FROM messages m JOIN users s ON s.id=m.sender_id JOIN users r ON r.id=m.receiver_id ORDER BY m.id DESC LIMIT 200")->fetchAll();
page_start('All Messages'); ?>
<div class="card"><div class="tw"><table class="tbl"><thead><tr><th>Time</th><th>From</th><th>To</th><th>Message</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $m): ?><tr><td><small><?= date('d M H:i', strtotime($m['created_at'])) ?></small></td><td><?= h($m['sname']) ?></td><td><?= h($m['rname']) ?></td><td><?= h(mb_substr($m['body'], 0, 160)) ?></td>
<td><form method="post" data-confirm="Delete this message?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn danger sm">Delete</button></form></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="5" class="muted">No messages yet.</td></tr>'; ?></tbody></table></div></div>
<?php page_end();
