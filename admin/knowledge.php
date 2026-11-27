<?php
// Admin ajar AI apa-apa maklumat baharu tanpa mengubah kod. Perubahan berkuat kuasa serta-merta.
require __DIR__ . '/../includes/layout.php';
require_login('admin'); $pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check(); $do = $_POST['do'] ?? ''; $id = (int)($_POST['id'] ?? 0);
  if ($do === 'save') {
    $t = trim($_POST['title'] ?? ''); $q = trim($_POST['questions'] ?? ''); $a = trim($_POST['answer'] ?? ''); $aud = in_array($_POST['audience'] ?? '', ['all', 'patient', 'staff'], true) ? $_POST['audience'] : 'all'; $on = !empty($_POST['active']) ? 1 : 0;
    if ($t === '' || $q === '' || $a === '') flash('err', 'Please fill in the title, at least one example question and the answer.');
    elseif ($id) { $pdo->prepare('UPDATE ai_knowledge SET title=?, questions=?, answer=?, audience=?, active=? WHERE id=?')->execute([$t, $q, $a, $aud, $on, $id]); log_act('ai_kb_edited', $t); flash('ok', 'Knowledge updated. The AI uses it immediately.'); }
    else { $pdo->prepare('INSERT INTO ai_knowledge (title, questions, answer, audience, active) VALUES (?,?,?,?,?)')->execute([$t, $q, $a, $aud, $on]); log_act('ai_kb_added', $t);
           if (!empty($_POST['from_unanswered'])) $pdo->prepare('DELETE FROM ai_unanswered WHERE message=?')->execute([$_POST['from_unanswered']]);
           flash('ok', 'Knowledge added. The AI uses it immediately.'); }
  } elseif ($do === 'toggle') { $pdo->prepare('UPDATE ai_knowledge SET active=1-active WHERE id=?')->execute([$id]); }
  elseif ($do === 'delete') { $pdo->prepare('DELETE FROM ai_knowledge WHERE id=?')->execute([$id]); log_act('ai_kb_deleted', "#$id"); flash('ok', 'Entry deleted.'); }
  elseif ($do === 'dismiss') { $pdo->prepare('DELETE FROM ai_unanswered WHERE id=?')->execute([$id]); }
  redirect('admin/knowledge.php');
}
$edit = null;
if (!empty($_GET['edit'])) { $s = $pdo->prepare('SELECT * FROM ai_knowledge WHERE id=?'); $s->execute([(int)$_GET['edit']]); $edit = $s->fetch() ?: null; }
$prefill = trim($_GET['q'] ?? '');
$rows = $pdo->query('SELECT * FROM ai_knowledge ORDER BY id DESC')->fetchAll();
$un = $pdo->query('SELECT message, MAX(id) id, COUNT(*) n FROM ai_unanswered GROUP BY message ORDER BY id DESC LIMIT 12')->fetchAll();
page_start('AI Knowledge'); ?>
<div class="card" style="margin-bottom:20px"><h3>🧠 Teach the AI</h3>
  <p class="muted">The AI already reads doctors, specialties, schedules, appointments and settings live from the website. Use this page for everything else — announcements, services, policies, locations, special hours. It works in any language and applies immediately.</p></div>

<form class="card" method="post" style="margin-bottom:20px"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
  <input type="hidden" name="from_unanswered" value="<?= h($prefill) ?>">
  <h3><?= $edit ? 'Edit entry' : 'Add an entry' ?></h3>
  <div class="grid g2 mt">
    <div class="field"><label>Title (for you)</label><input name="title" required placeholder="e.g. Flu vaccination campaign" value="<?= h($edit['title'] ?? '') ?>"></div>
    <div class="field"><label>Who can see this answer?</label><select name="audience"><?php foreach (['all' => 'Everyone', 'patient' => 'Patients only', 'staff' => 'Doctors & admins only'] as $k => $l) echo '<option value="' . $k . '"' . (($edit['audience'] ?? 'all') === $k ? ' selected' : '') . ">$l</option>"; ?></select></div>
  </div>
  <div class="field"><label>Example questions <small class="muted">(one per line — more variations means better matching)</small></label><textarea name="questions" required rows="4" placeholder="Is there a flu vaccine?&#10;Where can I get vaccinated?&#10;Ada vaksin selesema?"><?= h($edit['questions'] ?? $prefill) ?></textarea></div>
  <div class="field"><label>Answer</label><textarea name="answer" required rows="4" placeholder="Flu vaccines are available every Wednesday 2–4 PM. Book with Dr. Aisha."><?= h($edit['answer'] ?? '') ?></textarea></div>
  <div class="row"><label class="check"><input type="checkbox" name="active" value="1" <?= !$edit || $edit['active'] ? 'checked' : '' ?>> Active</label><button class="btn">Save</button><?php if ($edit): ?><a class="btn alt" href="<?= url('admin/knowledge.php') ?>">Cancel</a><?php endif; ?></div>
</form>

<?php if ($un): ?><div class="card" style="margin-bottom:20px"><h3>❓ Questions the AI couldn’t answer</h3><p class="muted">Patients asked these. Tap “Answer this” to teach the AI.</p>
  <div class="tw mt"><table class="tbl"><tbody><?php foreach ($un as $x): ?><tr><td><?= h($x['message']) ?> <?= $x['n'] > 1 ? '<span class="badge b-completed">×' . $x['n'] . '</span>' : '' ?></td>
    <td><div class="row"><a class="btn sm" href="?q=<?= urlencode($x['message']) ?>#top">Answer this</a>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn alt sm" name="do" value="dismiss">Dismiss</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>

<div class="card"><h3>Knowledge entries</h3><div class="tw mt"><table class="tbl"><thead><tr><th>Title</th><th>Audience</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><b><?= h($r['title']) ?></b><br><small class="muted"><?= h(mb_substr(strtok($r['questions'], "\n"), 0, 70)) ?>…</small></td><td><?= h($r['audience']) ?></td>
<td><span class="badge <?= $r['active'] ? 'b-confirmed' : 'b-cancelled' ?>"><?= $r['active'] ? 'active' : 'off' ?></span></td>
<td><div class="row"><a class="btn alt sm" href="?edit=<?= $r['id'] ?>">Edit</a>
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn alt sm" name="do" value="toggle"><?= $r['active'] ? 'Turn off' : 'Turn on' ?></button></form>
<form method="post" data-confirm="Delete this entry?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn danger sm" name="do" value="delete">Delete</button></form></div></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="4" class="muted">No entries yet.</td></tr>'; ?></tbody></table></div></div>
<?php page_end();
