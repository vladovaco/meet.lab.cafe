/* Nahrávanie z mikrofónu (MediaRecorder) + upload súboru + odoslanie porady.
 * Po zastavení sa dá pokračovať – každé pokračovanie je ďalšia časť nahrávky tej istej porady
 * (dva záznamy z MediaRecorderu sa nedajú spojiť do jedného súboru bez prekódovania).
 * Server časti prepíše a spojí do jedného prepisu so spoločnou časovou osou. */
(function () {
  const { api, toast } = window.Meet;
  const $ = (s) => document.querySelector(s);

  let mode = 'record';
  let mediaRecorder = null, stream = null, chunks = [], file = null, fileDuration = 0;
  let parts = [];              // hotové časti nahrávky: { blob, duration, session, url }
  let audioCtx = null, analyser = null, rafId = null;
  let startedAt = 0, timerId = null, durationSec = 0;
  let wakeLock = null;
  let session = null;          // id nahrávky v lokálnom úložisku (IndexedDB)
  let chunkIndex = 0;
  let userStopped = false;     // stop vyvolal používateľ (inak ide o prerušenie prehliadačom/OS)
  let paused = false;
  let lastChunkAt = 0, hiddenAt = 0, hiddenCount = 0, stallWarned = false;
  const store = window.RecStore && window.RecStore.available() ? window.RecStore : null;

  const btnStart = $('#rec-start'), btnPause = $('#rec-pause'), btnStop = $('#rec-stop');
  const timeEl = $('#rec-time'), statusEl = $('#rec-status'), preview = $('#rec-preview'), partsEl = $('#rec-parts');
  const canvas = $('#rec-wave'), ctx = canvas.getContext('2d');
  const submitBtn = $('#submit-btn'), form = $('#meeting-form'), errorEl = $('#form-error');
  const progress = $('#upload-progress'), progressBar = progress.querySelector('.progress-bar'), progressLabel = progress.querySelector('.progress-label');

  // Prepínanie módu
  document.querySelectorAll('.seg-btn').forEach(b => b.addEventListener('click', () => {
    mode = b.dataset.mode;
    document.querySelectorAll('.seg-btn').forEach(x => x.classList.toggle('is-active', x === b));
    $('.mode-record').hidden = mode !== 'record';
    $('.mode-upload').hidden = mode !== 'upload';
    updateSubmit();
  }));

  const recording = () => !!(mediaRecorder && mediaRecorder.state !== 'inactive');

  function updateSubmit() {
    submitBtn.disabled = mode === 'record' ? (parts.length === 0 || recording()) : !file;
  }

  function totalDuration() {
    return parts.reduce((a, p) => a + (p.duration || 0), 0);
  }

  function renderParts() {
    partsEl.innerHTML = '';
    parts.forEach((p, i) => {
      const el = document.createElement('div');
      el.className = 'rec-part';
      el.innerHTML = '<div class="rec-part-head"><strong></strong><button type="button" class="btn btn-ghost btn-sm">Zahodiť</button></div><audio controls preload="metadata"></audio>';
      el.querySelector('strong').textContent = (parts.length > 1 ? 'Časť ' + (i + 1) + ' · ' : 'Nahrávka · ') + fmt(p.duration) + ' · ' + (p.blob.size / 1048576).toFixed(1) + ' MB';
      el.querySelector('audio').src = p.url;
      el.querySelector('button').addEventListener('click', () => {
        if (!confirm('Zahodiť ' + (parts.length > 1 ? 'časť ' + (i + 1) : 'nahrávku') + '? Zmaže sa aj jej lokálna záloha.')) return;
        if (store && p.session) store.deleteSession(p.session).catch(() => {});
        URL.revokeObjectURL(p.url);
        parts.splice(i, 1);
        renderParts();
        if (!parts.length) { timeEl.textContent = '00:00'; statusEl.textContent = 'Pripravené.'; }
      });
      partsEl.appendChild(el);
    });
    preview.hidden = parts.length === 0;
    timeEl.textContent = fmt(totalDuration());
    if (!recording()) window.onbeforeunload = parts.length ? () => 'Nahrávka ešte nie je uložená. Naozaj chcete odísť?' : null;
    btnStart.setAttribute('aria-label', parts.length ? 'Pokračovať v nahrávaní (ďalšia časť)' : 'Začať nahrávať');
    updateSubmit();
  }

  function addPart(blob, duration, sessionId) {
    parts.push({ blob, duration, session: sessionId, url: URL.createObjectURL(blob) });
    renderParts();
  }

  function pickMimeType() {
    const types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/ogg', 'audio/aac', ''];
    for (const t of types) {
      if (t === '' || (window.MediaRecorder && MediaRecorder.isTypeSupported(t))) return t;
    }
    return '';
  }

  function fmt(sec) {
    sec = Math.floor(sec);
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    return (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  }

  function recordedMb() {
    return (chunks.reduce((a, b) => a + b.size, 0) / 1048576).toFixed(1);
  }

  function warn(msg) {
    statusEl.textContent = msg;
    statusEl.classList.add('is-warning');
    toast(msg, 'error');
    try { navigator.vibrate && navigator.vibrate([300, 150, 300]); } catch (e) {}
  }

  function tick() {
    const elapsed = (Date.now() - startedAt) / 1000;
    timeEl.textContent = fmt(totalDuration() + elapsed); // celková dĺžka porady vrátane predošlých častí
    // Strážca: MediaRecorder posiela blok každých 5 s. Ak dlho nič neprišlo, mikrofón nenahráva.
    if (!stallWarned && mediaRecorder && mediaRecorder.state === 'recording' && !document.hidden && Date.now() - lastChunkAt > 15000) {
      stallWarned = true;
      warn('Pozor: z mikrofónu neprichádzajú žiadne dáta. Nahrávanie pravdepodobne nefunguje – zastavte ho a začnite znova.');
    }
  }

  function draw() {
    if (!analyser) return;
    const data = new Uint8Array(analyser.frequencyBinCount);
    analyser.getByteTimeDomainData(data);
    const w = canvas.width, h = canvas.height;
    ctx.clearRect(0, 0, w, h);
    ctx.lineWidth = 2;
    ctx.strokeStyle = getComputedStyle(document.documentElement).getPropertyValue('--primary') || '#f97316';
    ctx.beginPath();
    const slice = w / data.length;
    for (let i = 0; i < data.length; i++) {
      const y = (data[i] / 128.0) * h / 2;
      i === 0 ? ctx.moveTo(0, y) : ctx.lineTo(i * slice, y);
    }
    ctx.stroke();
    rafId = requestAnimationFrame(draw);
  }

  async function requestWakeLock() {
    try { if ('wakeLock' in navigator) wakeLock = await navigator.wakeLock.request('screen'); } catch (e) { /* ignore */ }
  }

  async function start() {
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
      statusEl.textContent = 'Tento prehliadač nepodporuje nahrávanie. Použite nahranie súboru.';
      return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } });
    } catch (e) {
      statusEl.textContent = 'Prístup k mikrofónu bol zamietnutý. Povoľte ho v nastaveniach prehliadača.';
      return;
    }
    const mimeType = pickMimeType();
    chunks = [];
    userStopped = false; paused = false; stallWarned = false;
    statusEl.classList.remove('is-warning');
    mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType, audioBitsPerSecond: 64000 } : undefined);
    session = Date.now();
    chunkIndex = 0;
    if (store) {
      store.createSession({ id: session, startedAt: session, mimeType: mediaRecorder.mimeType || mimeType || 'audio/webm', title: form.querySelector('[name=title]')?.value || '' }).catch(() => {});
    }
    mediaRecorder.ondataavailable = (e) => {
      if (!(e.data && e.data.size)) return;
      chunks.push(e.data);
      lastChunkAt = Date.now();
      if (!paused && !statusEl.classList.contains('is-warning')) {
        statusEl.textContent = 'Nahráva sa… ' + recordedMb() + ' MB (' + (mediaRecorder.mimeType || 'predvolený formát') + ')';
      }
      if (store && session) {
        const sid = session, idx = chunkIndex++; // session sa po zastavení vynuluje skôr, než sa zápis dokončí
        const elapsed = (Date.now() - startedAt) / 1000;
        const bytes = chunks.reduce((a, b) => a + b.size, 0);
        store.addChunk(sid, idx, e.data)
          .then(() => store.updateSession(sid, { elapsed, chunks: idx + 1, bytes }))
          .catch(() => { statusEl.textContent = 'Pozor: lokálna záloha nahrávky zlyhala (málo miesta?). Nahráva sa ďalej.'; });
      }
    };
    mediaRecorder.onerror = (e) => {
      warn('Chyba nahrávania: ' + ((e.error && e.error.message) || 'neznáma') + '. Uložená časť zostane zachovaná.');
    };
    mediaRecorder.onstop = () => {
      const interrupted = !userStopped;
      if (interrupted) durationSec = (Date.now() - startedAt) / 1000;
      cleanup();
      finalize();
      if (interrupted) {
        warn('Nahrávanie prerušil prehliadač alebo systém (hovor, iná aplikácia s mikrofónom, zamknutie obrazovky) po ' + fmt(durationSec) + '. Zachytená časť je uložená nižšie – tlačidlom nahrávania pokračujte ďalšou časťou.');
      }
    };
    // Ak OS odoberie mikrofón (hovor, Siri, iná aplikácia), stopa skončí – MediaRecorder sa zastaví sám.
    stream.getAudioTracks().forEach(t => {
      t.addEventListener('ended', () => {
        if (!userStopped && mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
      });
      // mute = zariadenie dočasne neposiela zvuk (stlmený mikrofón, iná aplikácia ho prevzala) – nahráva sa ticho
      t.addEventListener('mute', () => {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') warn('Pozor: mikrofón prestal posielať zvuk (stlmený, odpojený alebo ho používa iná aplikácia). Nahráva sa ticho.');
      });
      t.addEventListener('unmute', () => {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') { statusEl.classList.remove('is-warning'); statusEl.textContent = 'Mikrofón opäť funguje. Nahráva sa… ' + recordedMb() + ' MB'; }
      });
    });
    mediaRecorder.start(5000); // chunk každých 5 s – pri páde ostane väčšina dát
    startedAt = Date.now(); lastChunkAt = startedAt;
    timerId = setInterval(tick, 500);
    try {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      analyser = audioCtx.createAnalyser(); analyser.fftSize = 1024;
      audioCtx.createMediaStreamSource(stream).connect(analyser);
      draw();
    } catch (e) { /* vizualizácia nie je kritická */ }
    requestWakeLock();
    btnStart.classList.add('is-recording');
    btnStart.setAttribute('aria-label', 'Nahráva sa');
    btnPause.hidden = false; btnStop.hidden = false;
    updateSubmit();
    statusEl.textContent = (parts.length ? 'Nahráva sa časť ' + (parts.length + 1) + '… ' : 'Nahráva sa… ') + '(' + (mediaRecorder.mimeType || 'predvolený formát') + '). Nechajte obrazovku zapnutú a stránku otvorenú.';
    window.onbeforeunload = () => 'Nahrávanie prebieha. Naozaj chcete odísť?';
  }

  /* Pauza = stlmenie mikrofónu, MediaRecorder beží ďalej (počas pauzy sa nahráva ticho).
   * MediaRecorder.pause()/resume() je na mobiloch (najmä Safari/iOS) nespoľahlivé – po obnovení
   * vznikne súbor, z ktorého sa prehrá/prepíše len úsek pred pauzou. */
  function togglePause() {
    if (!mediaRecorder || mediaRecorder.state === 'inactive') return;
    paused = !paused;
    stream.getAudioTracks().forEach(t => { t.enabled = !paused; });
    btnPause.textContent = paused ? '▶ Pokračovať' : '⏸ Pauza';
    statusEl.textContent = paused ? 'Pozastavené – mikrofón je stlmený.' : 'Nahráva sa… ' + recordedMb() + ' MB';
  }

  function cleanup() {
    if (stream) stream.getTracks().forEach(t => t.stop());
    clearInterval(timerId); cancelAnimationFrame(rafId);
    if (audioCtx) { audioCtx.close().catch(() => {}); audioCtx = null; analyser = null; }
    if (wakeLock) { wakeLock.release().catch(() => {}); wakeLock = null; }
    window.onbeforeunload = null;
  }

  function stop() {
    if (!mediaRecorder || mediaRecorder.state === 'inactive') return;
    userStopped = true;
    durationSec = (Date.now() - startedAt) / 1000;
    mediaRecorder.stop(); // onstop → cleanup + finalize
  }

  function finalize() {
    const type = mediaRecorder.mimeType || chunks[0]?.type || 'audio/webm';
    btnStart.classList.remove('is-recording');
    btnPause.hidden = true; btnStop.hidden = true; btnPause.textContent = '⏸ Pauza';
    paused = false;
    statusEl.classList.remove('is-warning');
    if (chunks.length) addPart(new Blob(chunks, { type }), durationSec, session);
    else if (store && session) store.deleteSession(session).catch(() => {});
    chunks = []; session = null;
    const total = totalDuration();
    statusEl.textContent = parts.length > 1
      ? 'Zastavené. ' + parts.length + ' časti, spolu ' + fmt(total) + '. Pokračujte tlačidlom nahrávania alebo vyplňte údaje a uložte.'
      : 'Zastavené: ' + fmt(total) + '. Pokračujte tlačidlom nahrávania alebo vyplňte údaje a uložte.';
    updateSubmit();
  }

  // Zmena zvukových zariadení (Bluetooth slúchadlá, dokovacia stanica, webkamera) môže nahrávaný mikrofón odpojiť.
  if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
    navigator.mediaDevices.addEventListener('devicechange', () => {
      if (!mediaRecorder || mediaRecorder.state === 'inactive' || !stream) return;
      setTimeout(() => {
        if (mediaRecorder && mediaRecorder.state !== 'inactive' && stream.getAudioTracks().some(t => t.readyState === 'ended' || t.muted)) {
          warn('Zvukové zariadenie sa zmenilo a mikrofón nenahráva. Skontrolujte slúchadlá/mikrofón.');
        }
      }, 1000);
    });
  }

  // Zámok obrazovky sa pri skrytí stránky uvoľní – po návrate ho treba vyžiadať znova.
  // Zároveň zistíme, či počas skrytia prichádzali dáta (mobilné prehliadače často na pozadí mikrofón zastavia).
  document.addEventListener('visibilitychange', () => {
    if (!mediaRecorder || mediaRecorder.state === 'inactive') return;
    if (document.hidden) {
      hiddenAt = Date.now(); hiddenCount = chunks.length;
      return;
    }
    requestWakeLock();
    const away = Date.now() - hiddenAt, since = hiddenAt, count = hiddenCount;
    hiddenAt = 0;
    // krátko počkáme – dáta nazbierané na pozadí môžu doraziť až po návrate
    if (since && away > 15000) setTimeout(() => {
      if (chunks.length === count && mediaRecorder && mediaRecorder.state !== 'inactive') {
        warn('Kým bola obrazovka zamknutá alebo stránka na pozadí (' + fmt(away / 1000) + '), prehliadač nenahrával. Nechajte obrazovku počas porady zapnutú.');
      }
    }, 2500);
    lastChunkAt = Math.max(lastChunkAt, Date.now() - 5000);
  });

  btnStart.addEventListener('click', () => { if (mediaRecorder && mediaRecorder.state !== 'inactive') stop(); else start(); });
  btnPause.addEventListener('click', togglePause);
  btnStop.addEventListener('click', stop);
  $('#rec-discard').addEventListener('click', () => {
    if (!confirm('Zahodiť celú nahrávku? Zmažú sa aj lokálne zálohy.')) return;
    parts.forEach(p => { if (store && p.session) store.deleteSession(p.session).catch(() => {}); URL.revokeObjectURL(p.url); });
    parts = []; renderParts();
    timeEl.textContent = '00:00'; statusEl.textContent = 'Pripravené.';
  });

  // Upload súboru
  const dz = $('#dropzone'), fileInput = $('#file-input'), fileInfo = $('#file-info');
  function setFile(f) {
    file = f || null;
    fileInfo.hidden = !file;
    if (file) {
      fileInfo.textContent = file.name + ' · ' + (file.size / 1048576).toFixed(1) + ' MB';
      // zisti trvanie
      try {
        const a = document.createElement('audio');
        a.preload = 'metadata';
        a.onloadedmetadata = () => { if (isFinite(a.duration)) fileDuration = a.duration; URL.revokeObjectURL(a.src); };
        a.src = URL.createObjectURL(file);
      } catch (e) {}
    }
    updateSubmit();
  }
  fileInput.addEventListener('change', () => setFile(fileInput.files[0]));
  ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.remove('is-over'); }));
  dz.addEventListener('drop', (e) => { const f = e.dataTransfer.files[0]; if (f) setFile(f); });

  // Rýchle pridanie účastníka
  $('#add-participant')?.addEventListener('click', async () => {
    const input = $('#new-participant');
    const name = input.value.trim();
    if (!name) return;
    try {
      const r = await api('/api/participants/quick', { name });
      const chips = $('#participant-chips');
      let existing = chips.querySelector('input[value="' + r.id + '"]');
      if (!existing) {
        const label = document.createElement('label');
        label.className = 'chip chip-select';
        label.innerHTML = '<input type="checkbox" name="participants[]" value="' + r.id + '" checked><span></span>';
        label.querySelector('span').textContent = r.name;
        chips.appendChild(label);
      } else {
        existing.checked = true;
      }
      input.value = '';
      toast(r.existing ? 'Účastník už existoval, označený.' : 'Účastník pridaný.');
    } catch (e) { toast(e.message, 'error'); }
  });
  $('#new-participant')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#add-participant').click(); } });

  // Obnova neuloženej nahrávky po páde prehliadača / vybití telefónu
  async function checkRecovery() {
    if (!store) return;
    let sessions = [];
    try { sessions = await store.listSessions(); } catch (e) { return; }
    sessions = sessions.filter(s => s.chunks > 0).sort((a, b) => b.startedAt - a.startedAt);
    if (!sessions.length) return;
    const box = $('#recovery');
    const list = $('#recovery-list');
    list.innerHTML = '';
    for (const s of sessions) {
      const li = document.createElement('div');
      li.className = 'recovery-item';
      const when = new Date(s.startedAt).toLocaleString('sk-SK', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' });
      li.innerHTML = '<div class="recovery-info"><strong></strong><span class="muted"></span></div><div class="recovery-actions"><button type="button" class="btn btn-primary btn-sm">Obnoviť</button><button type="button" class="btn btn-ghost btn-sm">Zahodiť</button></div>';
      li.querySelector('strong').textContent = (s.title ? s.title + ' · ' : '') + when;
      li.querySelector('span').textContent = fmt(s.elapsed || s.chunks * 5) + ' · ' + ((s.bytes || 0) / 1048576).toFixed(1) + ' MB';
      const [btnRestore, btnDrop] = li.querySelectorAll('button');
      btnRestore.addEventListener('click', async () => {
        btnRestore.disabled = true;
        try {
          const rows = await store.getChunks(s.id);
          rows.sort((a, b) => a.index - b.index);
          if (recording()) { toast('Najprv zastavte prebiehajúce nahrávanie.', 'error'); btnRestore.disabled = false; return; }
          const blobs = rows.map(r => r.blob);
          const restored = new Blob(blobs, { type: s.mimeType || blobs[0]?.type || 'audio/webm' });
          addPart(restored, s.elapsed || rows.length * 5, s.id);
          statusEl.textContent = 'Obnovená nahrávka: ' + fmt(s.elapsed || rows.length * 5) + ' · ' + (restored.size / 1048576).toFixed(1) + ' MB. Skontrolujte ju, prípadne pokračujte v nahrávaní, a uložte.';
          const titleInput = form.querySelector('[name=title]');
          if (s.title && titleInput && !titleInput.value) titleInput.value = s.title;
          document.querySelector('.seg-btn[data-mode=record]')?.click();
          li.remove();
          if (!list.children.length) box.hidden = true;
          form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
          toast('Obnova zlyhala: ' + e.message, 'error');
          btnRestore.disabled = false;
        }
      });
      btnDrop.addEventListener('click', async () => {
        if (!confirm('Naozaj zmazať túto neuloženú nahrávku?')) return;
        await store.deleteSession(s.id).catch(() => {});
        li.remove();
        if (!list.children.length) box.hidden = true;
      });
      list.appendChild(li);
    }
    box.hidden = false;
  }
  checkRecovery();

  // Odoslanie
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (mode === 'record' ? (!parts.length || recording()) : !file) return;
    errorEl.hidden = true;
    const fd = new FormData(form);
    const extOf = (t) => t.includes('mp4') || t.includes('m4a') ? 'm4a' : t.includes('ogg') ? 'ogg' : t.includes('mpeg') ? 'mp3' : t.includes('wav') ? 'wav' : 'webm';
    const round = (d) => String(Math.round((d || 0) * 100) / 100);
    if (mode === 'record') {
      const stamp = Date.now();
      parts.forEach((p, i) => {
        fd.append('audio[]', p.blob, 'nahravka-' + stamp + '-' + (i + 1) + '.' + extOf(p.blob.type));
        fd.append('durations[]', round(p.duration));
      });
    } else {
      fd.append('audio[]', file, file.name);
      fd.append('durations[]', round(fileDuration));
    }
    fd.append('source', mode);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.Meet.base + (form.dataset.action || '/api/meetings/upload'));
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.upload.onprogress = (ev) => {
      if (ev.lengthComputable) {
        const pct = Math.round(ev.loaded / ev.total * 100);
        progressBar.style.width = pct + '%';
        progressLabel.textContent = pct < 100 ? 'Nahráva sa… ' + pct + ' %' : 'Ukladá sa…';
      }
    };
    xhr.onload = () => {
      let r = {};
      try { r = JSON.parse(xhr.responseText); } catch (err) {}
      if (xhr.status >= 200 && xhr.status < 300 && r.url) {
        window.onbeforeunload = null;
        const go = () => { location.href = r.url; };
        const sessions = mode === 'record' ? parts.map(p => p.session).filter(Boolean) : [];
        if (store && sessions.length) Promise.all(sessions.map(id => store.deleteSession(id).catch(() => {}))).then(go, go); else go();
      } else {
        fail(r.error || ('Server vrátil chybu ' + xhr.status + (xhr.status === 413 ? ' – súbor je príliš veľký pre server.' : '')));
      }
    };
    xhr.onerror = () => fail('Spojenie so serverom zlyhalo. Skontrolujte pripojenie a skúste znova.');
    function fail(msg) {
      progress.hidden = true; submitBtn.disabled = false;
      errorEl.textContent = msg; errorEl.hidden = false;
    }
    progress.hidden = false; submitBtn.disabled = true; progressBar.style.width = '0%';
    xhr.send(fd);
  });
})();
