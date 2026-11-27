<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/../phpmailer/src/Exception.php';
require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/src/SMTP.php';

// Sulit / nyahsulit App Password (sama seperti sistem asal dalam zip)
function encrypt_pw(string $plain): string {
  $iv = random_bytes(16);
  return base64_encode($iv . openssl_encrypt($plain, 'aes-256-cbc', hex2bin(ENC_KEY), OPENSSL_RAW_DATA, $iv));
}
function decrypt_pw(string $stored): string {
  $raw = base64_decode($stored);
  return (string)openssl_decrypt(substr($raw, 16), 'aes-256-cbc', hex2bin(ENC_KEY), OPENSSL_RAW_DATA, substr($raw, 0, 16));
}

$GLOBALS['MAIL_ERR'] = '';

// Hantar emel guna akaun penghantar pertama yang admin tetapkan
function send_mail(string $to, string $name, string $subject, string $html): bool {
  $GLOBALS['MAIL_ERR'] = '';
  $acc = db()->query('SELECT * FROM sender_accounts ORDER BY id LIMIT 1')->fetch();
  if (!$acc) {
    $GLOBALS['MAIL_ERR'] = 'No sender account configured (Admin → Email Settings).';
    log_act('email_failed', "$to | $subject | no sender account");
    return false;
  }
  $m = new PHPMailer(true);
  try {
    $m->isSMTP();
    $m->Host = 'smtp.gmail.com';
    $m->SMTPAuth = true;
    $m->Username = $acc['email'];
    $m->Password = decrypt_pw($acc['app_password_enc']);
    $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $m->Port = 465;
    $m->Timeout = 12;
    $m->CharSet = 'UTF-8';
    $m->setFrom($acc['email'], $acc['display_name']);
    $m->addAddress($to, $name);
    $m->addReplyTo($acc['email'], $acc['display_name']);
    $m->isHTML(true);
    $m->Subject = $subject;
    $m->Body = $html;
    $m->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</tr>'], "\n", $html))));
    $m->send();
    log_act('email_sent', "$to | $subject");
    return true;
  } catch (MailException $e) {
    $GLOBALS['MAIL_ERR'] = $m->ErrorInfo;
    log_act('email_failed', "$to | $subject | " . $m->ErrorInfo);
    return false;
  }
}

// Templat emel berjenama (warna sama seperti website)
function mail_layout(string $heading, string $body, ?string $btn = null, ?string $link = null, string $btn2 = '', string $link2 = ''): string {
  $b = fn($l, $u, $bg, $c) => '<a href="' . h($u) . '" style="display:inline-block;background:' . $bg . ';color:' . $c . ';text-decoration:none;font-weight:700;padding:13px 26px;border-radius:999px;margin:6px 6px 0 0">' . h($l) . '</a>';
  $buttons = ($btn ? $b($btn, $link, '#2c6b7a', '#ffffff') : '') . ($btn2 ? $b($btn2, $link2, '#ffffff', '#2c6b7a;border:2px solid #8DBCC7') : '');
  return '<div style="background:#EBFFD8;padding:28px 12px;font-family:Segoe UI,Arial,sans-serif;color:#14404b">'
    . '<div style="max-width:560px;margin:auto;background:#fff;border-radius:22px;overflow:hidden;box-shadow:0 10px 30px rgba(31,95,112,.15)">'
    . '<div style="background:linear-gradient(135deg,#8DBCC7,#A4CCD9);padding:26px 30px"><div style="font-size:13px;letter-spacing:2px;color:#14404b;font-weight:700">' . h(strtoupper(SITE_NAME)) . '</div>'
    . '<div style="font-size:24px;font-weight:700;margin-top:6px">' . h($heading) . '</div></div>'
    . '<div style="padding:28px 30px;line-height:1.6;font-size:15px">' . $body . ($buttons ? '<p style="margin-top:22px">' . $buttons . '</p>' : '') . '</div>'
    . '<div style="background:#C4E1E6;padding:14px 30px;font-size:12px;color:#2c6b7a">This is an automated message from ' . h(SITE_NAME) . '. Please do not reply directly.</div>'
    . '</div></div>';
}

function appt_table(array $a, bool $forDoctor = false): string {
  $rows = [
    ['Date', fmt_date($a['appt_date'])], ['Time', fmt_time($a['appt_time'])],
    [$forDoctor ? 'Patient' : 'Doctor', $forDoctor ? $a['patient_name'] . ' (' . $a['matric_no'] . ')' : $a['doctor_name'] . ' · ' . $a['specialty']],
    ['Reason', $a['reason'] ?: '-'], ['Status', ucfirst($a['status'])],
  ];
  $t = '<table style="width:100%;border-collapse:collapse;margin:14px 0;background:#f4fbf0;border-radius:12px">';
  foreach ($rows as $r) $t .= '<tr><td style="padding:9px 14px;color:#5b7f89;width:90px">' . $r[0] . '</td><td style="padding:9px 14px;font-weight:600">' . h($r[1]) . '</td></tr>';
  return $t . '</table>';
}
