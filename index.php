<?php
require __DIR__ . '/includes/layout.php';
$u = me();
page_start('Welcome', 'pub');
?>
<nav class="pub-nav">
  <a class="brand" href="<?= url() ?>"><span class="logo">✚</span> <?= h(SITE_NAME) ?></a>
  <div class="row">
    <?php if ($u): ?><a class="btn" href="<?= home_for($u['role']) ?>">Go to Dashboard</a>
    <?php else: ?><a class="btn alt sm" href="<?= url('login.php') ?>">Login</a><a class="btn sm" href="<?= url('register.php') ?>">Register</a><?php endif; ?>
  </div>
</nav>
<div class="wrap">
  <section class="hero">
    <div>
      <span class="pill">🩺 Campus health, made simple</span>
      <h1>Your health, <em>booked</em> in a few taps.</h1>
      <p>Find a doctor, pick a time, and get instant email confirmation. Smart reminders make sure you never miss your appointment — and our AI assistant understands you in any language.</p>
      <div class="row"><a class="btn" href="<?= url($u ? 'patient/book.php' : 'register.php') ?>">Book an appointment →</a><a class="btn alt" href="#services">Explore services</a></div>
    </div>
    <div class="art">
      <div class="big"><svg viewBox="0 0 400 140" fill="none"><path d="M0 70h90l20-50 30 100 28-80 18 30h214" stroke="#2c6b7a" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      <div class="float">📅 Appointment confirmed</div><div class="float">🔔 Reminder sent</div><div class="float">🤖 AI assistant</div>
    </div>
  </section>

  <section class="grid g4 reveal">
    <div class="card stat"><b data-count="3" data-suf="+">0</b>Specialist doctors</div>
    <div class="card stat"><b data-count="24" data-suf="/7">0</b>Online booking</div>
    <div class="card stat"><b data-count="60" data-suf=" min">0</b>Smart reminder</div>
    <div class="card stat"><b data-count="50" data-suf="+">0</b>AI languages understood</div>
  </section>

  <section class="sec" id="services">
    <h2 class="reveal">Health services</h2><p class="sub reveal">Everything you need to know about our health services.</p>
    <div class="grid g3">
      <?php foreach ([['📅','Book Appointment','Choose your doctor, date and time. Instant email with your appointment details.'],['🩺','Find a Doctor','See each doctor’s speciality and weekly schedule before you book.'],['📋','Medical Records','Review your consultation history and doctor’s notes in one secure place.'],['💬','Contact Doctor','Send a message to your doctor for follow-up questions.'],['🔔','Smart Reminders','Automatic emails before your appointment, and if you are running late.'],['🤖','AI Assistant','Type “I want to see a doctor tomorrow” and get matching slots instantly.']] as $s): ?>
      <a class="card hv reveal svc" href="<?= $u ? home_for($u['role']) : url('register.php') ?>"><div class="ico"><?= $s[0] ?></div><h3><?= $s[1] ?></h3><p class="muted"><?= $s[2] ?></p><span class="go"><?= $u ? 'Open dashboard' : 'Get started' ?> →</span></a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="sec">
    <h2 class="reveal">How it works</h2><p class="sub reveal">From sign-up to consultation in four steps.</p>
    <div class="grid g4 steps">
      <?php foreach ([['Register','Create your account with your matric number and email.'],['Choose','Pick a doctor and an available slot.'],['Confirm','Your doctor accepts via a secure email link.'],['Consult','Get reminders, arrive on time, and see your doctor.']] as $s): ?>
      <div class="card hv reveal"><h3><?= $s[0] ?></h3><p class="muted"><?= $s[1] ?></p></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="sec">
    <h2 class="reveal">Daily health tips</h2><p class="sub reveal">Small habits, big difference.</p>
    <div class="grid g3">
      <?php foreach (health_tips() as $t): ?>
      <div class="card hv reveal"><div class="ico"><?= $t[0] ?></div><h3><?= h($t[1]) ?></h3><p class="muted"><?= h($t[2]) ?></p></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="sec reveal">
    <div class="sos">
      <div><h2>Need urgent help?</h2><p>Do not wait for an appointment in an emergency.</p></div>
      <div><small>Emergency (MERS)</small><br><b>999</b></div>
      <div><small>Mental health support (Talian HEAL)</small><br><b>15555</b></div>
    </div>
  </section>
</div>
<div class="foot">© <?= date('Y') ?> <?= h(SITE_NAME) ?> · Built with care for campus wellbeing</div>
<?php page_end('pub');
