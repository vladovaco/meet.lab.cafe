/* Nahrávanie z mikrofónu (MediaRecorder) + upload súboru + odoslanie porady */
(function () {
  const { api, toast } = window.Meet;
  const $ = (s) => document.querySelector(s);

  let mode = 'record';
  let mediaRecorder = null, stream = null, chunks = [], blob = null, file = null;
  let audioCtx = null, analyser = null, rafId = null;
  let startedAt = 0, pausedTotal = 0, pauseStart = 0, timerId = null, durationSec = 0;
  let wakeLock = null;
  let session = null;          // id nahrávky v lokálnom úložisku (IndexedDB)
  let chunkIndex = 0;
  const store = window.RecStore && window.RecStore.available() ? window.RecStore : null;

  const btnStart = $('#rec-start'), btnPause = $('#rec-pause'), btnStop = $('#rec-stop');
  const timeEl = $('#rec-time'), statusEl = $('#rec-status'), preview = $('#rec-preview'), audioEl = $('#rec-audio');
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

  function updateSubmit() {
    submitBtn.disabled = !(mode === 'record' ? blob : file);
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

  function tick() {
    const elapsed = (Date.now() - startedAt - pausedTotal) / 1000;
    timeEl.textContent = fmt(elapsed);
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
    mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType, audioBitsPerSecond: 64000 } : undefined);
    session = Date.now();
    chunkIndex = 0;
    if (store) {
      store.createSession({ id: session, startedAt: session, mimeType: mediaRecorder.mimeType || mimeType || 'audio/webm', title: form.querySelector('[name=title]').value }).catch(() => {});
    }
    mediaRecorder.ondataavailable = (e) => {
      if (!(e.data && e.data.size)) return;
      chunks.push(e.data);
      if (store && session) {
        const idx = chunkIndex++;
        const elapsed = (Date.now() - startedAt - pausedTotal) / 1000;
        store.addChunk(session, idx, e.data)
          .then(() => store.updateSession(session, { elapsed, chunks: idx + 1, bytes: chunks.reduce((a, b) => a + b.size, 0) }))
          .catch(() => { statusEl.textContent = 'Pozor: lokálna záloha nahrávky zlyhala (málo miesta?). Nahráva sa ďalej.'; });
      }
    };
    mediaRecorder.onstop = finalize;
    mediaRecorder.start(5000); // chunk každých 5 s – pri páde ostane väčšina dát
    startedAt = Date.now(); pausedTotal = 0;
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
    preview.hidden = true; blob = null; updateSubmit();
    statusEl.textContent = 'Nahráva sa… (' + (mediaRecorder.mimeType || 'predvolený formát') + ')';
    window.onbeforeunload = () => 'Nahrávanie prebieha. Naozaj chcete odísť?';
  }

  function togglePause() {
    if (!mediaRecorder) return;
    if (mediaRecorder.state === 'recording') {
      mediaRecorder.pause(); pauseStart = Date.now();
      btnPause.textContent = '▶ Pokračovať'; statusEl.textContent = 'Pozastavené.';
    } else if (mediaRecorder.state === 'paused') {
      mediaRecorder.resume(); pausedTotal += Date.now() - pauseStart;
      btnPause.textContent = '⏸ Pauza'; statusEl.textContent = 'Nahráva sa…';
    }
  }

  function stop() {
    if (!mediaRecorder) return;
    durationSec = (Date.now() - startedAt - pausedTotal) / 1000;
    mediaRecorder.stop();
    stream.getTracks().forEach(t => t.stop());
    clearInterval(timerId); cancelAnimationFrame(rafId);
    if (audioCtx) { audioCtx.close().catch(() => {}); audioCtx = null; analyser = null; }
    if (wakeLock) { wakeLock.release().catch(() => {}); wakeLock = null; }
    window.onbeforeunload = null;
  }

  function finalize() {
    const type = mediaRecorder.mimeType || chunks[0]?.type || 'audio/webm';
    blob = new Blob(chunks, { type });
    audioEl.src = URL.createObjectURL(blob);
    preview.hidden = false;
    btnStart.classList.remove('is-recording');
    btnPause.hidden = true; btnStop.hidden = true; btnPause.textContent = '⏸ Pauza';
    statusEl.textContent = 'Nahrávka hotová: ' + fmt(durationSec) + ' · ' + (blob.size / 1048576).toFixed(1) + ' MB. Vyplňte údaje a uložte.';
    updateSubmit();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  btnStart.addEventListener('click', () => { if (mediaRecorder && mediaRecorder.state !== 'inactive') stop(); else start(); });
  btnPause.addEventListener('click', togglePause);
  btnStop.addEventListener('click', stop);
  $('#rec-discard').addEventListener('click', () => {
    if (!confirm('Zahodiť nahrávku? Zmaže sa aj jej lokálna záloha.')) return;
    if (store && session) store.deleteSession(session).catch(() => {});
    session = null; blob = null; preview.hidden = true; timeEl.textContent = '00:00'; statusEl.textContent = 'Pripravené.'; updateSubmit();
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
        a.onloadedmetadata = () => { if (isFinite(a.duration)) durationSec = a.duration; URL.revokeObjectURL(a.src); };
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
  $('#add-participant').addEventListener('click', async () => {
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
  $('#new-participant').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#add-participant').click(); } });

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
          if (mediaRecorder && mediaRecorder.state !== 'inactive') stop();
          chunks = rows.map(r => r.blob);
          session = s.id; chunkIndex = rows.length;
          durationSec = s.elapsed || rows.length * 5;
          blob = new Blob(chunks, { type: s.mimeType || chunks[0]?.type || 'audio/webm' });
          audioEl.src = URL.createObjectURL(blob);
          preview.hidden = false;
          timeEl.textContent = fmt(durationSec);
          statusEl.textContent = 'Obnovená nahrávka: ' + fmt(durationSec) + ' · ' + (blob.size / 1048576).toFixed(1) + ' MB. Skontrolujte ju a uložte.';
          if (s.title && !form.querySelector('[name=title]').value) form.querySelector('[name=title]').value = s.title;
          document.querySelector('.seg-btn[data-mode=record]').click();
          box.hidden = true;
          updateSubmit();
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
    const audio = mode === 'record' ? blob : file;
    if (!audio) return;
    errorEl.hidden = true;
    const fd = new FormData(form);
    const ext = audio.type.includes('mp4') || audio.type.includes('m4a') ? 'm4a' : audio.type.includes('ogg') ? 'ogg' : audio.type.includes('mpeg') ? 'mp3' : audio.type.includes('wav') ? 'wav' : 'webm';
    fd.append('audio', audio, mode === 'record' ? 'nahravka-' + Date.now() + '.' + ext : file.name);
    fd.append('source', mode);
    fd.append('duration', String(Math.round(durationSec * 100) / 100));

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.Meet.base + '/api/meetings/upload');
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
        if (store && session) store.deleteSession(session).then(go, go); else go();
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
