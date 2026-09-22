/* Detail porady: polling stavu, prehrávač, editácia prepisu, rečníci, úlohy */
(function () {
  const { api, toast } = window.Meet;
  const head = document.querySelector('.meeting-head');
  if (!head) return;
  const meetingId = head.dataset.meetingId;
  const status = head.dataset.status;
  const processing = ['queued', 'transcribing', 'transcribed', 'analyzing'].includes(status);

  // ---- Polling stavu + web-mode spracovanie ----
  if (processing) {
    const label = document.getElementById('processing-label');
    const hint = document.getElementById('processing-hint');
    let runnerBusy = false;
    async function kickWorker() {
      if (head.dataset.processMode !== 'web' || runnerBusy) return;
      runnerBusy = true;
      try { await api('/api/jobs/run'); } catch (e) { /* worker beží inde alebo zlyhal – polling to ukáže */ }
      runnerBusy = false;
    }
    async function poll() {
      try {
        const s = await api('/api/meetings/' + meetingId + '/status', null, 'GET');
        if (label) label.textContent = s.status_label + '…';
        if (s.job && s.job.status === 'pending' && s.job.attempts > 0 && s.job.error && hint) {
          hint.textContent = 'Pokus ' + s.job.attempts + ' zlyhal: ' + s.job.error + ' – skúša sa znova.';
        }
        if (!['queued', 'transcribing', 'transcribed', 'analyzing'].includes(s.status)) {
          location.reload();
          return;
        }
        if (s.job && s.job.status === 'pending') kickWorker();
      } catch (e) { /* skús znova */ }
      setTimeout(poll, 5000);
    }
    kickWorker();
    setTimeout(poll, 3000);
  }

  // ---- Prehrávač + skok na čas + sledovanie ----
  const player = document.getElementById('player');
  document.addEventListener('click', (e) => {
    const b = e.target.closest('.js-seek');
    if (!b || !player) return;
    player.currentTime = parseFloat(b.dataset.t) || 0;
    player.play().catch(() => {});
  });
  const rows = Array.from(document.querySelectorAll('#transcript .seg-row'));
  const follow = document.getElementById('follow-audio');
  if (player && rows.length) {
    let current = null;
    player.addEventListener('timeupdate', () => {
      const t = player.currentTime;
      const row = rows.find(r => t >= parseFloat(r.dataset.start) && t < parseFloat(r.dataset.end) + 0.5);
      if (row && row !== current) {
        if (current) current.classList.remove('is-current');
        row.classList.add('is-current');
        current = row;
        if (follow?.checked && document.querySelector('[data-panel="transcript"]').classList.contains('is-active') && document.activeElement?.contentEditable !== 'true') {
          row.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
      }
    });
  }

  // ---- Ukážky hlasu rečníka (prehrá úsek a zastaví sa) ----
  let sampleStop = null, sampleBtn = null;
  function stopSample() {
    if (sampleBtn) { sampleBtn.classList.remove('is-playing'); sampleBtn.textContent = sampleBtn.dataset.label; }
    sampleStop = null; sampleBtn = null;
  }
  document.querySelectorAll('.js-sample').forEach(b => { b.dataset.label = b.textContent; });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('.js-sample');
    if (!b || !player) return;
    if (sampleBtn === b) { player.pause(); stopSample(); return; }
    stopSample();
    sampleBtn = b; sampleStop = parseFloat(b.dataset.end);
    b.classList.add('is-playing'); b.textContent = '■ ' + b.dataset.label.replace(/^▶ /, '');
    player.currentTime = parseFloat(b.dataset.start) || 0;
    player.play().catch(() => stopSample());
  });
  if (player) {
    player.addEventListener('timeupdate', () => { if (sampleStop !== null && player.currentTime >= sampleStop) { player.pause(); stopSample(); } });
    player.addEventListener('pause', () => { if (sampleStop !== null && player.currentTime < sampleStop - 0.3) stopSample(); });
    player.addEventListener('seeking', () => { if (sampleBtn && Math.abs(player.currentTime - parseFloat(sampleBtn.dataset.start)) > 0.5 && sampleStop !== null && player.currentTime > sampleStop) stopSample(); });
  }

  // ---- Filter v prepise ----
  const filter = document.getElementById('transcript-filter');
  filter?.addEventListener('input', () => {
    const q = filter.value.trim().toLowerCase();
    rows.forEach(r => r.classList.toggle('is-hidden', q !== '' && !r.textContent.toLowerCase().includes(q)));
  });

  // ---- Inline editácia (súhrn, segmenty) ----
  function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
  function bindEditable(el, buildPayload) {
    let last = el.innerText;
    const save = debounce(async () => {
      const val = el.innerText.trim();
      if (val === last.trim()) return;
      el.classList.add('is-saving');
      try { await api(el.dataset.url, buildPayload(val)); last = val; toast('Uložené'); }
      catch (e) { toast(e.message, 'error'); }
      el.classList.remove('is-saving');
    }, 900);
    el.addEventListener('input', save);
    el.addEventListener('blur', save);
  }
  const summary = document.getElementById('summary');
  if (summary) bindEditable(summary, (v) => ({ summary: v }));
  document.querySelectorAll('.seg-text[data-url]').forEach(el => bindEditable(el, (v) => ({ text: v })));

  // ---- Rečníci ----
  document.querySelectorAll('.js-speaker-select').forEach(sel => {
    sel.addEventListener('change', async () => {
      const li = sel.closest('.speaker');
      const label = li.dataset.label;
      let payload = { speaker_label: label, participant_id: sel.value === 'new' ? 0 : parseInt(sel.value, 10) };
      if (sel.value === 'new') {
        const name = prompt('Meno nového účastníka:');
        if (!name) { sel.value = '0'; return; }
        payload.new_name = name.trim();
      }
      try {
        const r = await api(sel.dataset.url, payload);
        const name = r.participant ? r.participant.name : label;
        const color = r.participant ? r.participant.color : '#9ca3af';
        li.querySelector('.speaker-name strong').textContent = name;
        li.querySelector('.avatar').style.setProperty('--c', color);
        li.querySelector('.avatar').textContent = initials(name);
        document.querySelectorAll('.seg-row[data-speaker="' + label + '"]').forEach(r => {
          r.querySelector('.seg-name').textContent = name;
          r.querySelector('.avatar').style.setProperty('--c', color);
          r.querySelector('.avatar').textContent = initials(name);
        });
        if (r.participant && sel.value === 'new') {
          const opt = new Option(r.participant.name, r.participant.id, true, true);
          sel.insertBefore(opt, sel.querySelector('option[value="new"]'));
          document.querySelectorAll('select[data-field="participant_id"], #add-task-form select').forEach(s => s.add(new Option(r.participant.name, r.participant.id)));
        }
        toast('Rečník priradený');
      } catch (e) { toast(e.message, 'error'); }
    });
  });
  function initials(name) {
    return name.split(/\s+/).slice(0, 2).map(p => p[0]?.toUpperCase() || '').join('') || '?';
  }

  // ---- Úlohy: editácia polí, mazanie, pridanie ----
  document.querySelectorAll('#task-list .task').forEach(li => {
    const id = li.dataset.id;
    li.querySelectorAll('.js-task-field').forEach(f => f.addEventListener('change', async () => {
      try { await api('/api/action-items/' + id + '/update', { [f.dataset.field]: f.value }); toast('Uložené'); }
      catch (e) { toast(e.message, 'error'); }
    }));
    const desc = li.querySelector('.task-desc[contenteditable]');
    if (desc) {
      desc.dataset.url = '/api/action-items/' + id + '/update';
      bindEditable(desc, (v) => ({ description: v }));
    }
    li.querySelector('.js-task-delete')?.addEventListener('click', async () => {
      if (!confirm('Zmazať úlohu?')) return;
      try { await api('/api/action-items/' + id + '/delete'); li.remove(); } catch (e) { toast(e.message, 'error'); }
    });
  });
  const addForm = document.getElementById('add-task-form');
  addForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(addForm);
    try {
      await api(addForm.dataset.url, Object.fromEntries(fd.entries()));
      location.hash = 'tasks';
      location.reload();
    } catch (err) { toast(err.message, 'error'); }
  });

  // ---- Kopírovanie zápisu ----
  document.getElementById('copy-notes')?.addEventListener('click', async () => {
    const text = document.getElementById('notes-clipboard')?.value || '';
    try { await navigator.clipboard.writeText(text); toast('Zápis skopírovaný do schránky'); }
    catch (e) { toast('Kopírovanie sa nepodarilo', 'error'); }
  });
})();
