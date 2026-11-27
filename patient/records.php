<?php
require __DIR__ . '/../includes/layout.php';
$u = require_login('patient');
$st = db()->prepare('SELECT r.*, d.username doctor_name FROM medical_records r JOIN users d ON d.id=r.doctor_id WHERE r.patient_id=? ORDER BY r.created_at DESC');
$st->execute([$u['id']]); $rows = $st->fetchAll();
db()->prepare('UPDATE medical_records SET patient_seen=1 WHERE patient_id=?')->execute([$u['id']]);
page_start('Medical Records'); ?>
<?php foreach ($rows as $r): ?>
<div class="card hv" style="margin-bottom:16px"><div class="row sp"><h3><?= !$r['patient_seen'] ? '<span class="badge b-new">New</span> ' : '' ?><?= h($r['diagnosis']) ?></h3><span class="muted"><?= fmt_date($r['created_at']) ?></span></div>
<p class="muted">Seen by <?= h($r['doctor_name']) ?></p><p style="margin-top:8px"><?= nl2br(h($r['notes'])) ?></p></div>
<?php endforeach; if (!$rows): ?><div class="card"><p class="muted">No medical records yet. They appear here after a doctor completes your consultation.</p></div><?php endif; ?>
<?php page_end();
