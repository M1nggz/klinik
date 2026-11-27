(() => {
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const csrf = ($('meta[name=csrf]') || {}).content, base = window.APP_BASE || '';

  // Reveal on scroll
  const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }), { threshold: .12 });
  $$('.reveal').forEach((el, i) => { el.style.transitionDelay = (i % 4) * 80 + 'ms'; io.observe(el); });

  // Count-up numbers
  const co = new IntersectionObserver(es => es.forEach(e => {
    if (!e.isIntersecting) return; co.unobserve(e.target);
    const el = e.target, to = +el.dataset.count, suf = el.dataset.suf || '', t0 = performance.now();
    const tick = t => { const p = Math.min(1, (t - t0) / 1300); el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3))) + suf; if (p < 1) requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  }), { threshold: .4 });
  $$('[data-count]').forEach(el => co.observe(el));

  // Progress bars
  setTimeout(() => $$('.bar i[data-w]').forEach(b => b.style.width = b.dataset.w + '%'), 250);

  // Button ripple
  document.addEventListener('click', e => {
    const b = e.target.closest('.btn'); if (!b) return;
    const r = b.getBoundingClientRect(), s = Math.max(r.width, r.height), d = document.createElement('span');
    d.className = 'ripple'; d.style.cssText = `width:${s}px;height:${s}px;left:${e.clientX - r.left - s / 2}px;top:${e.clientY - r.top - s / 2}px`;
    b.appendChild(d); setTimeout(() => d.remove(), 600);
  });

  // Toasts auto-dismiss
  $$('.toast').forEach((t, i) => setTimeout(() => { t.style.opacity = 0; t.style.transform = 'translateX(30px)'; setTimeout(() => t.remove(), 500); }, 4500 + i * 600));

  // Sidebar (mobile) + password toggle
  const burger = $('#burger'); if (burger) burger.onclick = () => $('#side').classList.toggle('open');
  $$('.pw button').forEach(b => b.onclick = () => { const i = b.previousElementSibling; i.type = i.type === 'password' ? 'text' : 'password'; });
  $$('[data-confirm]').forEach(f => f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));

  // Booking: load slots
  const bf = $('#bookForm');
  if (bf) {
    const dateEl = $('#date'), box = $('#slots'), pre = bf.dataset.time || '';
    const load = async () => {
      const doc = ($('input[name=doctor_id]:checked') || {}).value;
      if (!doc || !dateEl.value) { box.innerHTML = '<span class="muted">Choose a doctor and a date to see available times.</span>'; return; }
      box.innerHTML = '<span class="muted">Loading…</span>';
      const r = await (await fetch(`${base}/api/slots.php?doctor=${doc}&date=${dateEl.value}`)).json();
      if (!r.slots.length) { box.innerHTML = '<span class="muted">No free slots on this day. ' + (r.next ? `Next available: <a href="#" id="goNext" data-d="${r.next}">${r.next_label}</a>` : '') + '</span>'; const g = $('#goNext'); if (g) g.onclick = e => { e.preventDefault(); dateEl.value = g.dataset.d; load(); }; return; }
      box.innerHTML = r.slots.map((s, i) => `<label><input type="radio" name="time" value="${s.v}" ${s.v === pre ? 'checked' : ''} required><span style="animation-delay:${i * 30}ms">${s.l}</span></label>`).join('');
    };
    $$('input[name=doctor_id]').forEach(i => i.onchange = load); dateEl.onchange = load; load();
  }

  // AI chat widget (pesakit / doktor / admin)
  const chat = $('#chat');
  if (chat) {
    const box = $('#chatBox'), msgs = $('#chatMsgs'), inp = $('#chatIn'), ep = chat.dataset.endpoint || base + '/api/ai.php';
    const add = (txt, who, chips) => {
      const d = document.createElement('div'); d.className = 'cm ' + who; d.textContent = txt;
      if (chips && chips.length) {
        const c = document.createElement('div'); c.className = 'chips';
        chips.forEach(x => { const a = document.createElement('a'); a.textContent = x.label;
          if (x.url) a.href = x.url; else { a.href = '#'; a.onclick = e => { e.preventDefault(); send(x.send || x.label); }; }
          c.appendChild(a); });
        d.appendChild(c);
      }
      msgs.appendChild(d); msgs.scrollTop = msgs.scrollHeight; return d;
    };
    const send = async m => {
      add(m, 'you'); const t = add('', 'bot'); t.innerHTML = '<span class="typing"><span></span><span></span><span></span></span>';
      try {
        const r = await (await fetch(ep, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: JSON.stringify({ message: m }) })).json();
        t.remove(); add(r.reply, 'bot', r.chips);
      } catch (_) { t.remove(); add('Sorry, something went wrong. Please try again.', 'bot'); }
    };
    $('#chatFab').onclick = () => {
      box.classList.toggle('open');
      if (!msgs.children.length) { let q = []; try { q = JSON.parse(chat.dataset.quick || '[]'); } catch (_) {} add(chat.dataset.welcome || 'Hi! 👋', 'bot', q); }
      inp.focus();
    };
    $('#chatClose').onclick = () => box.classList.remove('open');
    $('#chatForm').onsubmit = e => { e.preventDefault(); const m = inp.value.trim(); if (!m) return; inp.value = ''; send(m); };
  }
})();
