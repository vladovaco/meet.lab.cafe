/* Spoločné utility: CSRF fetch, taby, prepínanie úloh, service worker */
window.Meet = (function () {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const base = (document.body.dataset.base || '/').replace(/\/$/, '');

  async function api(path, data, method = 'POST') {
    const opts = { method, headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (data instanceof FormData) {
      data.append('_csrf', csrf);
      opts.body = data;
    } else if (data) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify({ ...data, _csrf: csrf });
    }
    const res = await fetch(base + path, opts);
    let json = null;
    try { json = await res.json(); } catch (e) { /* ignore */ }
    if (!res.ok) throw new Error(json?.error || ('Chyba servera (' + res.status + ')'));
    return json;
  }

  function toast(msg, type = 'success') {
    let el = document.getElementById('toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'toast';
      el.style.cssText = 'position:fixed;left:50%;bottom:calc(var(--tabbar-h) + var(--safe-b) + 12px);transform:translateX(-50%);padding:.6rem 1rem;border-radius:10px;font-size:.9rem;font-weight:600;z-index:100;box-shadow:var(--shadow);max-width:90vw;transition:opacity .3s';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.style.background = type === 'error' ? '#fee2e2' : '#dcfce7';
    el.style.color = type === 'error' ? '#991b1b' : '#166534';
    el.style.opacity = '1';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.opacity = '0'; }, 2500);
  }

  // Taby
  document.querySelectorAll('.tabs .tab').forEach(tab => {
    tab.addEventListener('click', () => {
      const name = tab.dataset.tab;
      tab.closest('.tabs').querySelectorAll('.tab').forEach(t => t.classList.toggle('is-active', t === tab));
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('is-active', p.dataset.panel === name));
      try { history.replaceState(null, '', '#' + name); } catch (e) {}
    });
  });
  if (location.hash) {
    const t = document.querySelector('.tabs .tab[data-tab="' + location.hash.slice(1) + '"]');
    if (t) t.click();
  }

  // Odškrtávanie úloh (kdekoľvek)
  document.addEventListener('change', async (ev) => {
    const cb = ev.target.closest('.js-task-toggle');
    if (!cb) return;
    const li = cb.closest('.task');
    try {
      const r = await api('/api/action-items/' + cb.dataset.id + '/toggle');
      li.classList.toggle('is-done', r.status === 'done');
      cb.checked = r.status === 'done';
    } catch (e) {
      cb.checked = !cb.checked;
      toast(e.message, 'error');
    }
  });

  // PWA
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    navigator.serviceWorker.register(base + '/sw.js').catch(() => {});
  }

  return { api, toast, base, csrf };
})();
