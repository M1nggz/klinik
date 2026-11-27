# CampusCare Clinic – Panduan Pemasangan

## 1. XAMPP
1. Salin folder `klinik` ke `C:\xampp\htdocs\klinik`.
2. Mulakan **Apache** dan **MySQL** dalam XAMPP.
3. Buka `http://localhost/klinik` – database `klinik_sihat` + jadual + data contoh dicipta automatik.
   (Jika import `database.sql` manual, buka laman sekali – akaun admin & doktor contoh akan dimasukkan automatik jika belum ada.)

## 2. Akaun lalai (TUKAR SELEPAS LOGIN PERTAMA)
| Peranan | ID (No. Matrik) | Kata laluan |
|---|---|---|
| Admin | ADMIN001 | Admin@123 |
| Doktor | DR001 / DR002 / DR003 | Doctor@123 |
Emel doktor contoh (`dr001@example.com`) mesti ditukar kepada emel sebenar di **Admin → Doctors → Edit**.
Patient daftar sendiri di `register.php`; doktor hanya dicipta admin.

## 3. Emel (PHPMailer)
Login admin → **Email Settings** → tambah Gmail + **App Password** → tekan "Send test email".

## 4. Peringatan lewat appointment
Automatik pada setiap lawatan halaman (maksimum sekali seminit). Untuk lebih tepat:
- Windows Task Scheduler setiap 5 min: `C:\xampp\php\php.exe C:\xampp\htdocs\klinik\cron.php`
- Atau cron-job.org: `https://laman-anda/cron.php?key=CRON_KEY`
Tetapan masa ada dalam `config.php` (REMIND_BEFORE_MIN, LATE_AFTER_MIN, NOSHOW_AFTER_MIN).

## 5. AI Assistant (Python + ngrok)
```
cd ai
pip install -r requirements.txt
set AI_KEY=tukar-kunci-rahsia-ai
python ai_server.py          # tetingkap 1
ngrok http 5000              # tetingkap 2
```
Tampal URL https ngrok ke `AI_URL` dalam `config.php`. `AI_KEY` mesti sama di kedua-dua tempat.
Percubaan pertama memuat turun model (~470MB). URL ngrok percuma berubah setiap kali dimulakan semula.

## 6. Hosting InfinityFree
- Cipta database di panel, isi `DB_*`, `BASE_PATH ''`, `BASE_URL` dalam `config.php`, import `database.sql`.
- PENTING: sahkan sama ada InfinityFree membenarkan sambungan SMTP keluar (port 465). Jika disekat, emel tidak akan dihantar dan
  anda perlu guna perkhidmatan emel melalui HTTP API (cth. Brevo/Resend) – ubah fungsi `send_mail()` dalam `includes/mailer.php`.


## 7. AI untuk Doktor & Admin
- Ikon robot 🤖 kini ada pada semua halaman doktor dan admin (endpoint `api/ai_staff.php`). Server Python yang sama digunakan.
- Privasi: kata laluan, kunci, kandungan mesej peribadi, no. telefon/IC dan diagnosis/nota klinikal tidak didedahkan. Hanya nama niat direkod dalam activity log, bukan teks soalan.
- Pilihan (jawapan umum/penulisan bebas): tetapkan `ANTHROPIC_API_KEY` sebelum `python ai_server.py`
  (PowerShell: `$env:ANTHROPIC_API_KEY="..."`; model lalai boleh ditukar dengan `LLM_MODEL`). Soalan umum (bukan data klinik) akan dihantar ke Anthropic API.
  Tanpa kunci ini, AI hanya menjawab kemahiran terbina dalam (jadual, statistik, status, templat, panduan).

## 8. AI sentiasa terkini
- Doktor baharu, kepakaran, jadual, appointment dan tetapan dibaca terus dari database pada setiap soalan — tiada latihan semula.
- Doktor baharu mesti set jadual (Doctor → My Schedule) sebelum slot muncul; AI akan beritahu pesakit jika belum ada jadual.
- Admin → **AI Knowledge**: ajar AI maklumat lain (pengumuman, perkhidmatan, polisi) dan lihat soalan yang AI tak dapat jawab.


## 9. Kemahiran AI terbaharu
- **Pesakit**: terangkan simptom ("saya sedang stress", "my teeth hurt") untuk cadangan doktor sesuai + slot; appointment sedang diproses / missed / rejected; sejarah; follow-up; konsultasi; rekod perubatan; mesej masuk. Tanda bahaya (cth sakit dada) terus diarahkan ke 999 / Talian HEAL 15555.
- **Doktor**: jadual & waktu available, sejarah appointment, bilangan konsultasi & follow-up, sebab appointment, pesakit tertentu, mesej masuk.
- **Admin**: diagnosis emel gagal (punca + cara baiki), log aktiviti mengikut hari (hari ini / semalam / hari sebelum / minggu ini) + carian, mesej masuk, appointment (doktor, pesakit, sebab, follow-up, konsultasi).
- Mulakan semula `python ai_server.py` selepas kemas kini.
