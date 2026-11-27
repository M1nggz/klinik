<?php
require __DIR__ . '/../includes/boot.php';
header('Content-Type: application/json');
if (!me()) { http_response_code(401); exit('{"slots":[]}'); }
$doc = (int)($_GET['doctor'] ?? 0); $date = $_GET['date'] ?? '';
$out = array_map(fn($s) => ['v' => $s, 'l' => fmt_time($s)], slots_for($doc, $date));
$next = $out ? null : next_available($doc, date('Y-m-d', strtotime(max($date, date('Y-m-d')) . ' +1 day')));
echo json_encode(['slots' => $out, 'next' => $next, 'next_label' => $next ? fmt_date($next) : null]);
