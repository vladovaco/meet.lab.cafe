/* Výber účastníkov / štítkov s našepkávaním namiesto dlhého zoznamu čipov.
 * Vybrané položky sú čipy so skrytým <input name="…[]">, takže formulár sa odosiela ako predtým.
 * Neexistujúcu položku sa dá hneď vytvoriť (Enter alebo „+ Pridať …“).
 * Priečinok: voľba „+ Nový priečinok…“ v selecte otvorí inline pole na vytvorenie. */
(function () {
  const { api, toast } = window.Meet;
  const norm = (s) => (s || '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
  const CREATE = {
    participant: { url: '/api/participants/quick', label: 'Pridať účastníka' },
    tag:         { url: '/api/tags/quick',         label: 'Pridať štítok' },
  };

  document.querySelectorAll('.picker').forEach(initPicker);

  function initPicker(root) {
    let data = { options: [], selected: [] };
    try { data = JSON.parse(root.querySelector('.picker-data').textContent); } catch (e) { /* prázdne */ }
    const name = root.dataset.name, prefix = root.dataset.prefix || '', create = CREATE[root.dataset.create];
    const box = root.querySelector('.picker-box'), selEl = root.querySelector('.picker-selected');
    const input = root.querySelector('.picker-input'), list = root.querySelector('.picker-list');
    const options = data.options;
    const selected = new Set(data.selected.map(Number));
    let items = [], active = -1;

    function renderSelected() {
      selEl.innerHTML = '';
      options.filter(o => selected.has(o.id)).forEach(o => {
        const chip = document.createElement('span');
        chip.className = 'chip picker-chip';
        chip.style.setProperty('--c', o.color);
        chip.innerHTML = '<span></span><button type="button" aria-label="Odobrať">×</button><input type="hidden">';
        chip.querySelector('span').textContent = prefix + o.name;
        const hidden = chip.querySelector('input');
        hidden.name = name; hidden.value = o.id;
        chip.querySelector('button').addEventListener('click', (e) => { e.stopPropagation(); selected.delete(o.id); renderSelected(); input.focus(); });
        selEl.appendChild(chip);
      });
    }

    function renderList() {
      const q = norm(input.value);
      const matches = options
        .filter(o => !selected.has(o.id))
        .filter(o => !q || norm(o.name).includes(q) || norm(o.aliases).includes(q))
        .sort((a, b) => (q ? (norm(b.name).startsWith(q) - norm(a.name).startsWith(q)) : 0) || a.name.localeCompare(b.name, 'sk'))
        .slice(0, 8);
      items = matches.map(o => ({ type: 'option', o }));
      const exact = options.some(o => norm(o.name) === q || norm(o.aliases).split(/\s*,\s*/).includes(q));
      if (q && create && !exact) items.push({ type: 'create', name: input.value.trim().replace(/^#/, '') });
      list.innerHTML = '';
      items.forEach((it, i) => {
        const li = document.createElement('li');
        li.setAttribute('role', 'option');
        if (it.type === 'option') {
          li.innerHTML = '<span class="picker-dot"></span><span></span>';
          li.querySelector('.picker-dot').style.background = it.o.color;
          li.lastChild.textContent = prefix + it.o.name;
        } else {
          li.className = 'picker-create';
          li.textContent = '+ ' + create.label + ' „' + it.name + '“';
        }
        li.addEventListener('mousedown', (e) => { e.preventDefault(); choose(i); });
        list.appendChild(li);
      });
      if (!items.length) {
        const li = document.createElement('li');
        li.className = 'picker-empty';
        li.textContent = options.length && options.every(o => selected.has(o.id)) ? 'Všetko je vybrané.' : 'Nič sa nenašlo.';
        list.appendChild(li);
      }
      active = items.length ? 0 : -1;
      highlight();
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function highlight() {
      Array.from(list.children).forEach((li, i) => li.classList.toggle('is-active', i === active));
      list.children[active]?.scrollIntoView({ block: 'nearest' });
    }

    function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); }

    async function choose(i) {
      const it = items[i];
      if (!it) return;
      if (it.type === 'option') {
        selected.add(it.o.id);
      } else {
        try {
          const r = await api(create.url, { name: it.name });
          let o = options.find(x => x.id === Number(r.id));
          if (!o) { o = { id: Number(r.id), name: r.name, color: r.color || '#6366f1', aliases: '' }; options.push(o); }
          selected.add(o.id);
          toast(r.existing ? 'Už existoval – vybraný.' : (create.label.replace('Pridať', 'Pridaný') + '.'));
        } catch (e) { toast(e.message, 'error'); return; }
      }
      input.value = '';
      renderSelected();
      renderList();
      input.focus();
    }

    input.addEventListener('focus', renderList);
    input.addEventListener('input', renderList);
    input.addEventListener('blur', () => setTimeout(close, 120));
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) renderList(); else { active = Math.min(active + 1, items.length - 1); highlight(); } }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); highlight(); }
      else if (e.key === 'Enter') { if (!list.hidden && active >= 0) { e.preventDefault(); choose(active); } else if (input.value.trim()) e.preventDefault(); }
      else if (e.key === 'Escape') { close(); }
      else if (e.key === 'Backspace' && input.value === '' && selected.size) {
        const last = Array.from(selEl.querySelectorAll('input')).pop();
        if (last) { selected.delete(Number(last.value)); renderSelected(); }
      }
    });
    box.addEventListener('click', () => input.focus());
    renderSelected();
  }

  // ---- Priečinok: inline pridanie nového ----
  document.querySelectorAll('select.js-folder-select').forEach(sel => {
    const wrap = document.createElement('div');
    wrap.className = 'inline-add';
    wrap.hidden = true;
    wrap.innerHTML = '<input type="text" class="input" placeholder="Názov nového priečinka"><button type="button" class="btn btn-primary">Pridať</button><button type="button" class="btn btn-ghost">Zrušiť</button>';
    (sel.closest('label') || sel).insertAdjacentElement('afterend', wrap); // mimo <label>, aby klik nevybral select
    const [nameInput, btnAdd, btnCancel] = wrap.querySelectorAll('input, button');
    let previous = sel.value;
    sel.addEventListener('focus', () => { previous = sel.value; });
    sel.addEventListener('change', () => {
      if (sel.value !== '__new') { previous = sel.value; return; }
      wrap.hidden = false; nameInput.value = ''; nameInput.focus();
    });
    const cancel = () => { wrap.hidden = true; sel.value = previous; };
    async function add() {
      const name = nameInput.value.trim();
      if (!name) { nameInput.focus(); return; }
      btnAdd.disabled = true;
      try {
        const r = await api('/api/folders/quick', { name });
        let opt = sel.querySelector('option[value="' + r.id + '"]');
        if (!opt) { opt = new Option(r.name, r.id); sel.insertBefore(opt, sel.querySelector('option[value="__new"]')); }
        sel.value = String(r.id); previous = sel.value;
        wrap.hidden = true;
        toast(r.existing ? 'Priečinok už existoval – vybraný.' : 'Priečinok vytvorený.');
      } catch (e) { toast(e.message, 'error'); }
      btnAdd.disabled = false;
    }
    btnAdd.addEventListener('click', add);
    btnCancel.addEventListener('click', cancel);
    nameInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); add(); }
      else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
    });
  });
})();
