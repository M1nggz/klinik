<?php
require_once __DIR__ . '/boot.php';

function nav_items(string $role): array {
  return match ($role) {
    'patient' => [['patient/dashboard.php', '🏠', 'Dashboard'], ['patient/book.php', '📅', 'Book Appointment'], ['patient/appointments.php', '🗓️', 'My Appointments'],
                  ['patient/doctors.php', '🩺', 'Doctors'], ['patient/records.php', '📋', 'Medical Records'], ['messages.php', '💬', 'Messages'], ['profile.php', '👤', 'Profile']],
    'doctor'  => [['doctor/dashboard.php', '🏠', 'Dashboard'], ['doctor/schedule.php', '⏰', 'My Schedule'], ['doctor/appointments.php', '🗓️', 'Appointments'],
                  ['messages.php', '💬', 'Messages'], ['profile.php', '👤', 'Profile']],
    default   => [['admin/dashboard.php', '🏠', 'Dashboard'], ['admin/users.php?role=patient', '🧑‍🎓', 'Patients'], ['admin/users.php?role=doctor', '🩺', 'Doctors'],
                  ['admin/appointments.php', '🗓️', 'Appointments'], ['admin/messages.php', '💬', 'Messages'], ['admin/activity.php', '📊', 'Activity Log'],
                  ['admin/mail.php', '✉️', 'Email Settings'], ['admin/knowledge.php', '🧠', 'AI Knowledge'], ['profile.php', '👤', 'Profile']],
  };
}

// Bilangan item baharu bagi setiap menu (untuk titik merah)
function nav_badges(array $u): array {
  $id = (int)$u['id']; $b = ['messages.php' => unread_count($id)];
  if ($u['role'] === 'patient') {
    $b['patient/appointments.php'] = (int)qv('SELECT COUNT(*) FROM appointments WHERE patient_id=? AND patient_seen=0', [$id]);
    $b['patient/records.php'] = (int)qv('SELECT COUNT(*) FROM medical_records WHERE patient_id=? AND patient_seen=0', [$id]);
  } elseif ($u['role'] === 'doctor') {
    $b['doctor/appointments.php'] = (int)qv("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND (status='pending' OR doctor_seen=0)", [$id]);
  } else {
    $b['admin/appointments.php'] = (int)qv('SELECT COUNT(*) FROM appointments WHERE id>?', [seen_get("seen_{$id}_appt")]);
    $b['admin/users.php?role=patient'] = (int)qv("SELECT COUNT(*) FROM users WHERE role='patient' AND id>?", [seen_get("seen_{$id}_patient")]);
    $b['admin/messages.php'] = (int)qv('SELECT COUNT(*) FROM messages WHERE id>?', [seen_get("seen_{$id}_msg")]);
    $b['admin/mail.php'] = (int)qv('SELECT COUNT(*) FROM sender_accounts') ? (int)qv("SELECT COUNT(*) FROM activity_log WHERE action='email_failed' AND id>?", [seen_get("seen_{$id}_fail")]) : 1;
  }
  return $b;
}

function page_start(string $title, string $mode = 'app'): void {
  $u = me();
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
     . '<title>' . h($title) . ' · ' . h(SITE_NAME) . '</title><meta name="csrf" content="' . csrf() . '">'
     . '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">'
     . '<link rel="stylesheet" href="' . url('assets/css/style.css') . '?v=' . @filemtime(__DIR__ . '/../assets/css/style.css') . '"></head><body class="page-' . $mode . '">'
     . '<div class="blobs"><span></span><span></span><span></span></div><script>window.APP_BASE=' . json_encode(BASE_PATH) . ';</script>';
  if ($mode === 'app' && $u) {
    $cur = $_SERVER['SCRIPT_NAME'] . (isset($_GET['role']) ? '?role=' . $_GET['role'] : '');
    $badges = nav_badges($u); $unread = array_sum($badges);
    echo '<div class="app"><aside class="side" id="side"><a class="brand" href="' . url() . '"><span class="logo">✚</span> ' . h(SITE_NAME) . '</a><nav>';
    foreach (nav_items($u['role']) as [$p, $ic, $lb]) {
      $on = str_ends_with($cur, '/' . $p) ? ' on' : '';
      echo '<a class="' . trim($on) . '" href="' . url($p) . '"><i>' . $ic . '</i>' . $lb . (($badges[$p] ?? 0) ? '<b class="dot" title="' . $badges[$p] . ' new"></b>' : '') . '</a>';
    }
    echo '<a class="logout" href="' . url('logout.php') . '"><i>🚪</i>Logout</a>';
    echo '</nav><div class="me"><div class="av">' . h(mb_strtoupper(mb_substr($u['username'], 0, 1))) . '</div><div><b>' . h($u['username']) . '</b><small>' . ucfirst($u['role']) . '</small></div>'
       . '<a class="out" href="' . url('logout.php') . '" title="Logout">⏻</a></div></aside>'
       . '<main class="main"><header class="top"><button class="burger" id="burger" aria-label="Menu">☰' . ($unread ? '<b class="dot"></b>' : '') . '</button><h1>' . h($title) . '</h1><span class="date">' . date('l, d M Y') . '</span></header><div class="content">';
  }
  echo '<div class="toasts">';
  foreach ($_SESSION['flash'] ?? [] as [$t, $m]) echo '<div class="toast ' . h($t) . '">' . h($m) . '</div>';
  unset($_SESSION['flash']);
  echo '</div>';
}

function page_end(string $mode = 'app'): void {
  $u = me();
  if ($mode === 'app' && $u) echo '</div></main></div>';
  if ($u) {
    $staff = $u['role'] !== 'patient';
    $cfg = $staff
      ? ['api/ai_staff.php', 'AI Staff Assistant', 'Ask me anything · private data stays private', 'e.g. What is my schedule today?',
         'Hi ' . $u['username'] . '! 👋 I can pull up schedules, stats and status, draft notes and messages, and explain how things work. Ask in any language.',
         $u['role'] === 'doctor'
           ? [['label' => "Today's schedule", 'send' => 'What is my schedule today?'], ['label' => 'Pending requests', 'send' => 'Any pending requests?'], ['label' => 'Next patient', 'send' => 'Who is my next patient?'], ['label' => 'My availability', 'send' => 'Show my available schedule'], ['label' => 'History', 'send' => 'Show my appointment history'], ['label' => 'New messages', 'send' => 'Do I have new messages?']]
           : [['label' => 'System overview', 'send' => 'Give me a system overview'], ['label' => 'Pending bookings', 'send' => 'How many pending appointments?'], ['label' => 'Email status', 'send' => 'Is the email system working?'], ['label' => 'Email problems', 'send' => 'Why are emails failing and how do I fix it?'], ['label' => 'Activity today', 'send' => 'What activity happened today?'], ['label' => 'Yesterday', 'send' => 'Show the activity log for yesterday']]]
      : ['api/ai.php', 'AI Appointment Assistant', 'Ask in any language', 'e.g. I want to see a doctor tomorrow',
         "Hi! 👋 I'm your appointment assistant. Try: “I want to see a doctor tomorrow”. You can write in any language.", [['label' => 'Available appointments', 'send' => 'Show available appointments'], ['label' => 'My appointments', 'send' => 'Which appointments are in progress?'], ['label' => 'New messages', 'send' => 'Do I have new messages?'], ['label' => 'Medical records', 'send' => 'Show my medical records'], ['label' => 'I feel stressed', 'send' => 'I am feeling stressed']]];
    echo '<div id="chat" class="chat" data-endpoint="' . h(url($cfg[0])) . '" data-welcome="' . h($cfg[4]) . '" data-quick="' . h(json_encode($cfg[5], JSON_UNESCAPED_UNICODE)) . '">'
       . '<button class="chat-fab" id="chatFab" aria-label="AI Assistant">🤖<span class="ping"></span></button>'
       . '<div class="chat-box" id="chatBox"><div class="chat-head"><b>' . h($cfg[1]) . '</b><small>' . h($cfg[2]) . '</small><button id="chatClose">×</button></div>'
       . '<div class="chat-msgs" id="chatMsgs"></div><form class="chat-form" id="chatForm"><input id="chatIn" placeholder="' . h($cfg[3]) . '" autocomplete="off"><button class="btn sm">Send</button></form></div></div>';
  }
  echo '<script src="' . url('assets/js/app.js') . '?v=' . @filemtime(__DIR__ . '/../assets/js/app.js') . '"></script></body></html>';
  if (function_exists('fastcgi_finish_request')) { @flush(); fastcgi_finish_request(); }
  run_reminders_throttled();
}
