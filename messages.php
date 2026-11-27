<?php
// Mesej antara patient & doktor (satu fail untuk kedua-dua peranan)
require __DIR__ . '/includes/layout.php';
$u = require_login(); if ($u['role'] === 'admin') redirect('admin/messages.php');
$pdo = db(); $other = $u['role'] === 'patient' ? 'doctor' : 'patient';
$with = (int)($_GET['with'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $to = (int)$_POST['to']; $body = trim(mb_substr($_POST['body'] ?? '', 0, 2000));
  $ok = $pdo->prepare('SELECT 1 FROM users WHERE id=? AND role=?'); $ok->execute([$to, $other]);
  if ($body !== '' && $ok->fetch()) { $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, body) VALUES (?,?,?)')->execute([$u['id'], $to, $body]); log_act('message_sent', "to #$to"); }
  redirect('messages.php?with=' . $to);
}
// Senarai perbualan: patient nampak semua doktor; doktor nampak pesakit yang pernah mesej/booking
if ($u['role'] === 'patient') $list = $pdo->query("SELECT id, username, specialty sub FROM users WHERE role='doctor' AND status='active' ORDER BY username")->fetchAll();
else { $q = $pdo->prepare("SELECT DISTINCT p.id, p.username, p.matric_no sub FROM users p WHERE p.role='patient' AND (p.id IN (SELECT sender_id FROM messages WHERE receiver_id=?) OR p.id IN (SELECT receiver_id FROM messages WHERE sender_id=?)) ORDER BY p.username"); $q->execute([$u['id'], $u['id']]); $list = $q->fetchAll(); }
$msgs = [];
if ($with) {
  $pdo->prepare('UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=?')->execute([$with, $u['id']]);
  $m = $pdo->prepare('SELECT * FROM messages WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?) ORDER BY id'); $m->execute([$u['id'], $with, $with, $u['id']]); $msgs = $m->fetchAll();
}
page_start('Messages'); ?>
<div class="grid" style="grid-template-columns:minmax(200px,280px) 1fr">
  <div class="card"><h3>Conversations</h3><div class="convo mt"><?php foreach ($list as $c): ?>
    <a class="<?= $with === (int)$c['id'] ? 'on' : '' ?>" href="<?= url('messages.php?with=' . $c['id']) ?>"><b><?= h($c['username']) ?></b><br><small class="muted"><?= h($c['sub']) ?></small></a>
  <?php endforeach; if (!$list) echo '<p class="muted">No conversations yet.</p>'; ?></div></div>
  <div class="card"><?php if (!$with): ?><p class="muted">Select a <?= $other ?> to start chatting.</p><?php else: ?>
    <div class="thread" id="thread"><?php foreach ($msgs as $m): ?><div class="bub <?= (int)$m['sender_id'] === (int)$u['id'] ? 'me2' : '' ?>"><?= nl2br(h($m['body'])) ?><small><?= date('d M, g:i A', strtotime($m['created_at'])) ?></small></div><?php endforeach; ?></div>
    <form method="post" class="row mt" style="flex-wrap:nowrap"><?= csrf_field() ?><input type="hidden" name="to" value="<?= $with ?>"><input name="body" placeholder="Type a message…" required autocomplete="off"><button class="btn">Send</button></form>
    <script>var t=document.getElementById('thread');t.scrollTop=t.scrollHeight</script><?php endif; ?></div>
</div>
<?php page_end();
