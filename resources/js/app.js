// Tiny enhancements. Every page works without this file.

// Week page: show the "N to book · M to cancel" bar only while something is ticked.
// The meals and buttons are not inside the form element: they join it with form="week-form".
document.querySelectorAll('[data-week-form]').forEach((form) => {
  const bar = document.querySelector('[data-week-bar]');
  const text = document.querySelector('[data-week-count]');
  if (!bar || !text) return;

  const update = () => {
    const ticked = [...form.elements].filter((el) => el.type === 'checkbox' && el.checked);
    const book = ticked.filter((box) => box.value === 'book').length;
    const cancel = ticked.length - book;
    bar.classList.toggle('hidden', ticked.length === 0);
    text.replaceChildren();
    const part = (template, count, color) => {
      const span = document.createElement('span');
      span.className = `font-medium ${color}`;
      span.textContent = template.replace('{count}', String(count));
      return span;
    };
    if (book) text.append(part(bar.dataset.book, book, 'text-lime'));
    if (book && cancel) text.append(' · ');
    if (cancel) text.append(part(bar.dataset.cancel, cancel, 'text-danger-bright'));
  };

  document.addEventListener('change', (event) => event.target.form === form && update());
  form.addEventListener('reset', () => setTimeout(update));
  update();
});

// Report page: preview of the chosen picture, with the same checks as the server.
document.querySelectorAll('[data-report-form]').forEach((form) => {
  const input = form.querySelector('[data-picture-input]');
  const label = form.querySelector('[data-picture-label]');
  const preview = form.querySelector('[data-picture-preview]');
  const img = form.querySelector('[data-picture-img]');
  const remove = form.querySelector('[data-picture-remove]');
  const status = form.querySelector('[data-report-status]');
  if (!input || !label || !preview || !img || !remove) return;

  const clear = () => {
    if (img.src.startsWith('blob:')) URL.revokeObjectURL(img.src);
    input.value = '';
    img.removeAttribute('src');
    preview.classList.add('hidden');
    label.classList.remove('hidden');
  };
  const refuse = (text) => {
    clear();
    if (status) {
      status.textContent = text;
      status.className = 'text-[15px] text-danger';
    }
  };

  input.addEventListener('change', () => {
    const file = input.files && input.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) return refuse(form.dataset.typeError);
    if (file.size > 5 * 1024 * 1024) return refuse(form.dataset.sizeError);
    if (status) status.textContent = '';
    img.src = URL.createObjectURL(file);
    preview.classList.remove('hidden');
    label.classList.add('hidden');
  });
  remove.addEventListener('click', clear);
});

// Team page: the group list only offers the groups of the chosen meal.
document.querySelectorAll('[data-switch-form]').forEach((form) => {
  const select = form.querySelector('[data-group-select]');
  if (!select) return;

  const filter = () => {
    const meal = form.querySelector('input[name="to"]:checked')?.value;
    select.querySelectorAll('option[data-meal]').forEach((option) => {
      option.hidden = option.disabled = option.dataset.meal !== meal;
    });
    select.querySelectorAll('optgroup').forEach((group) => {
      group.hidden = ![...group.querySelectorAll('option')].some((option) => !option.hidden);
    });
    if (select.selectedOptions[0]?.hidden) select.value = '';
  };
  form.addEventListener('change', (event) => {
    if (event.target.name === 'to') {
      select.value = '';
      filter();
    }
  });
  filter();
});

// Admin "new user": the meal ticks follow the department's default meals until they are changed by hand.
document.querySelectorAll('form[data-dept-meals]').forEach((form) => {
  const defaults = JSON.parse(form.dataset.deptMeals);
  const select = form.querySelector('[data-dept-select]');
  const note = form.querySelector('[data-dept-note]');
  if (!select) return;
  let touched = false;
  form.querySelectorAll('[data-meal-tick]').forEach((box) => box.addEventListener('change', () => (touched = true)));
  select.addEventListener('change', () => {
    if (touched) return;
    const meals = defaults[select.value] ?? {};
    form.querySelectorAll('[data-meal-tick]').forEach((box) => (box.checked = Boolean(meals[box.dataset.mealTick])));
    if (note) note.textContent = note.textContent.replace(/Department \S+/, `Department ${select.value}`).replace(/Département \S+/, `Département ${select.value}`);
  });
});

// Admin penalties: the booked count follows the day and meal, and the lines + total are previewed live.
// The server works the amounts out again on save; this is only the preview.
document.querySelectorAll('form[data-penalty-form]').forEach((form) => {
  const prices = JSON.parse(form.dataset.prices);
  const currency = form.dataset.currency;
  const $ = (sel) => form.querySelector(sel);
  const round2 = (n) => Math.round(n * 100) / 100;
  const money = (n) => `${n.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
  const on = (name) => $(`[name="${name}"]`)?.checked;
  const delayPercent = (m) => (m > 15 ? 10 : m >= 11 ? 5 : m >= 5 ? 2 : 0);

  const recalc = () => {
    const meal = $('[data-penalty-meal]').value;
    const unit = prices[meal] ?? 0;
    const meals = Math.max(0, Math.round(Number($('[data-penalty-meals]').value) || 0));
    const service = round2(meals * unit);
    $('[data-penalty-service]').textContent = service.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    $('[data-penalty-unit]').textContent = `${meals} × ${money(unit)}`;

    const lines = [];
    if (on('s1')) form.querySelectorAll('[name="kpis[]"]:checked').forEach((k) => lines.push([1, k.dataset.reason, Number(k.dataset.percent), 0]));
    if (on('s2')) {
      const minutes = Number($('[name="delay"]').value);
      if (on('significant')) lines.push([2, 'Delay with significant impact', Math.min(100, Math.max(0, Number($('[name="significant_percent"]').value) || 0)), 0]);
      else if (delayPercent(minutes)) lines.push([2, `Delivery delay of ${minutes} minutes`, delayPercent(minutes), 0]);
    }
    if (on('s3')) {
      const tier = $('[name="shortfall"]').selectedOptions[0];
      const missing = Math.max(0, Math.round(Number($('[name="undelivered"]').value) || 0));
      lines.push([3, tier.dataset.reason, Number(tier.dataset.percent), round2(missing * unit)]);
    }
    if (on('s4') && $('[name="quality_percent"]').value !== '') {
      lines.push([4, $('[name="quality"]').selectedOptions[0].textContent, Math.min(100, Math.max(0, Number($('[name="quality_percent"]').value))), 0]);
    }

    const box = $('[data-penalty-preview]');
    box.replaceChildren();
    if (!lines.length) {
      const p = document.createElement('p');
      p.className = 'text-mute';
      p.textContent = form.dataset.empty;
      box.append(p);
      return;
    }
    let total = 0;
    const row = (label, value, strong) => {
      const div = document.createElement('div');
      div.className = `flex justify-between gap-3${strong ? ' mt-1 font-medium' : ''}`;
      const dt = document.createElement('dt');
      dt.className = strong ? '' : 'text-mute';
      dt.textContent = label;
      const dd = document.createElement('dd');
      dd.className = 'shrink-0 tabular-nums';
      dd.textContent = value;
      div.append(dt, dd);
      box.append(div);
    };
    for (const [section, reason, percent, refund] of lines) {
      const amount = round2((service * percent) / 100 + refund);
      total += amount;
      row(`S${section} · ${reason} · ${percent}%${refund ? ` + refund ${money(refund)}` : ''}`, money(amount));
    }
    row(form.dataset.totalLabel, money(round2(total)), true);
  };

  const refreshBooked = async () => {
    const date = $('[data-penalty-date]').value;
    const meal = $('[data-penalty-meal]').value;
    if (!date) return;
    try {
      const res = await fetch(`/penalties/booked?date=${encodeURIComponent(date)}&meal=${meal}`, { headers: { Accept: 'application/json' } });
      const { booked } = await res.json();
      $('[data-penalty-meals]').value = booked;
      const hint = $('[data-penalty-meals]').closest('div').querySelector('p');
      if (hint) hint.textContent = form.dataset.bookedLabel.replace('{0}', booked).replace('{1}', $('[data-penalty-meal]').selectedOptions[0].textContent.toLowerCase());
    } catch {}
    recalc();
  };

  form.addEventListener('input', recalc);
  form.addEventListener('change', (e) => (e.target.matches('[data-penalty-date], [data-penalty-meal]') ? refreshBooked() : recalc()));
  recalc();
});

// Serve (door phone): Web NFC on Android Chrome. Each badge is checked by the server, which answers with the
// result card only, so scanning keeps running. The card clears itself after 8 seconds.
document.querySelectorAll('[data-serve]').forEach((root) => {
  const $ = (sel) => root.querySelector(sel);
  const slot = $('[data-serve-slot]');
  const idle = slot.innerHTML;
  const message = $('[data-nfc-message]');
  const start = $('[data-nfc-start]');
  const onBadge = $('[data-nfc-on]');
  const last = $('[data-nfc-last]');
  let clearTimer = null;
  let lastScan = { uid: '', at: 0 };
  let reader = null; // must stay referenced, or Chrome may stop reading

  const say = (text) => {
    message.textContent = text;
    message.classList.toggle('hidden', !text);
  };
  const showHtml = (html) => {
    slot.innerHTML = html;
    const card = slot.querySelector('[data-serve-result]');
    const counts = document.querySelector('[data-serve-counts]');
    if (card && counts) counts.textContent = card.dataset.counts;
    clearTimeout(clearTimer);
    if (card && card.dataset.kind === 'result') clearTimer = setTimeout(() => (slot.innerHTML = idle), 8000);
  };
  const post = async (url, fields) => {
    const body = new FormData();
    body.set('_token', root.dataset.token);
    body.set('meal', root.dataset.meal);
    for (const [k, v] of Object.entries(fields)) body.set(k, v);
    try {
      const res = await fetch(`${url}?partial=1`, { method: 'POST', body, headers: { Accept: 'text/html' } });
      if (!res.ok) throw new Error(String(res.status));
      showHtml(await res.text());
    } catch {
      say(root.dataset.tNetwork);
    }
  };
  const cardNumber = (uid) => {
    const hex = uid.replace(/[^0-9a-f]/gi, '').toUpperCase();
    return hex.length === 8 ? parseInt(hex.match(/../g).reverse().join(''), 16) : null;
  };

  // ---- NFC check panel: what NFC needs, a permission button, and a test mode that only shows what a card gives.
  const panel = $('[data-nfc-check]');
  const supported = 'NDEFReader' in window;
  const secure = window.isSecureContext;
  let permission = 'unknown';
  let scanning = false;
  let testing = false;
  const formatId = (uid) => uid.replace(/[^0-9a-f]/gi, '').toUpperCase().match(/../g)?.join(':') ?? uid;

  const setRow = (name, variant, status) => {
    const row = panel?.querySelector(`[data-row="${name}"]`);
    if (!row) return;
    row.querySelectorAll('[data-v]').forEach((v) => v.classList.toggle('hidden', v.dataset.v !== variant));
    const icon = row.querySelector('[data-icon]');
    icon.className = `mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full ${status === true ? 'bg-booked-ink text-page' : status === false ? 'bg-lime text-ink' : 'bg-cream-deep text-mute'}`;
    icon.textContent = status === true ? '✓' : status === false ? '✕' : '?';
  };
  const refresh = () => {
    if (!panel) return;
    setRow('secure', secure ? 'ok' : 'bad', secure);
    setRow('supported', !secure ? 'wait' : supported ? 'ok' : 'bad', !secure ? null : supported);
    const waiting = !secure || !supported;
    setRow('permission', waiting ? 'wait' : permission, waiting ? null : permission === 'granted' ? true : permission === 'denied' ? false : null);
    setRow('scanning', scanning ? 'on' : 'off', scanning);
    const ask = panel.querySelector('[data-nfc-ask]');
    const granted = permission === 'granted' && scanning;
    ask.disabled = granted;
    ask.querySelector('[data-ask-label]').textContent = granted ? ask.dataset.tGranted : ask.dataset.tAsk;
    const test = panel.querySelector('[data-nfc-test]');
    test.textContent = testing ? test.dataset.tStop : test.dataset.tStart;
    test.classList.toggle('bg-ink', testing);
    test.classList.toggle('text-page', testing);
    panel.querySelector('[data-test-area]').classList.toggle('hidden', !testing);
  };
  const readPermission = async () => {
    try {
      const status = await navigator.permissions.query({ name: 'nfc' });
      permission = status.state;
      status.onchange = () => {
        permission = status.state;
        refresh();
      };
    } catch {
      permission = 'unknown';
    }
    refresh();
  };
  const addTestRead = (uid) => {
    const list = panel.querySelector('[data-test-reads]');
    panel.querySelector('[data-test-waiting]').classList.add('hidden');
    const n = uid ? cardNumber(uid) : null;
    const item = document.createElement('li');
    item.className = `rounded-[16px] px-4 py-3 text-[13px] ${uid ? 'bg-booked' : 'bg-lime/20'}`;
    const line = (cls, text) => {
      const p = document.createElement('p');
      p.className = `font-mono tabular-nums ${cls}`;
      p.textContent = text;
      item.append(p);
    };
    if (uid) {
      line('text-[15px]', `${panel.dataset.tId} ${formatId(uid)}`);
      line('text-[15px]', `${panel.dataset.tReversed} ${formatId(uid).split(':').reverse().join(':')}`);
      line('text-[17px] font-semibold', `${panel.dataset.tNumber} ${n ?? `— ${panel.dataset.tNot4}`}`);
    } else {
      line('text-[15px]', `${panel.dataset.tId} —`);
    }
    list.prepend(item);
  };

  if (!supported) {
    say(root.dataset.tUnsupported);
    start.style.display = 'none';
  }
  const startNfc = async () => {
    say('');
    try {
      reader = new window.NDEFReader();
      reader.onreading = (e) => {
        const uid = e.serialNumber || '';
        last.textContent = `${new Date().toLocaleTimeString()} · ${uid ? `${root.dataset.tCard} ${cardNumber(uid) ?? '—'}` : '—'}`;
        if (testing) return addTestRead(uid); // test mode: nothing is checked or saved
        if (!uid) return say(root.dataset.tNoid);
        // A badge held to the phone is read several times: ignore repeats for 3 seconds.
        if (lastScan.uid === uid && Date.now() - lastScan.at < 3000) return;
        lastScan = { uid, at: Date.now() };
        say('');
        post('/serve/badge', { uid });
      };
      reader.onreadingerror = () => (testing ? addTestRead('') : say(root.dataset.tUnreadable));
      await reader.scan();
      scanning = true;
      if (panel && !testing) panel.open = false;
      start.style.display = 'none';
      onBadge.classList.remove('hidden');
      onBadge.classList.add('flex');
      last.classList.remove('hidden');
    } catch (error) {
      say(error && error.name === 'NotAllowedError' ? root.dataset.tDenied : root.dataset.tOff);
    }
    readPermission();
  };
  start.addEventListener('click', startNfc);
  if (panel) {
    panel.querySelector('[data-nfc-ask]').addEventListener('click', startNfc);
    panel.querySelector('[data-nfc-test]').addEventListener('click', () => {
      if (!testing) {
        panel.querySelector('[data-test-reads]').innerHTML = '';
        panel.querySelector('[data-test-waiting]').classList.remove('hidden');
        if (!scanning) startNfc();
      }
      testing = !testing;
      refresh();
    });
    panel.querySelectorAll('[data-origin]').forEach((o) => (o.textContent = window.location.origin));
    panel.open = true;
    readPermission();
  }

  // OK / Cancel on a card: back to waiting without leaving the page (and without stopping NFC).
  slot.addEventListener('click', (e) => {
    const dismiss = e.target.closest('[data-serve-dismiss]');
    if (dismiss) {
      e.preventDefault();
      slot.innerHTML = idle;
    }
  });
});

// Serve: the 80 mm meal ticket, printed from a small window.
document.addEventListener('click', (e) => {
  const button = e.target.closest('[data-print-ticket]');
  if (!button) return;
  const lang = document.querySelector('[data-serve]')?.dataset.ticketLang || 'en';
  const labels = {
    en: { name: 'Full name', department: 'Department', meal: 'Meal', time: 'Time' },
    fr: { name: 'Nom complet', department: 'Département', meal: 'Repas', time: 'Heure' },
    ar: { name: 'الاسم الكامل', department: 'القسم', meal: 'الوجبة', time: 'الوقت' },
  }[lang];
  const esc = (v) => String(v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const popup = window.open('', '_blank', 'width=420,height=600');
  if (!popup) return;
  const time = new Date().toLocaleString(lang === 'ar' ? 'ar' : lang === 'fr' ? 'fr-FR' : 'en-GB');
  popup.document.write(`<!doctype html><html><head><title>Meal ticket</title><style>
    @page{size:80mm auto;margin:0}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#111;width:72mm;margin:0 auto;padding:0 0 4mm;font-size:11px}main{width:100%;border:3px double #111;border-radius:3mm;padding:0 3mm 3mm}.logo{display:block;width:30mm;height:auto;margin:-1mm auto 4mm}.row{display:flex;gap:2mm;align-items:baseline;margin:3mm 0;font-size:10px;white-space:nowrap}.name-row{display:block;white-space:normal}.name-row .value{display:block;margin-top:1mm}.label{font-size:9px}.value{overflow:hidden;text-overflow:ellipsis;font-weight:bold;font-size:11px}
  </style></head><body><main dir="${lang === 'ar' ? 'rtl' : 'ltr'}"><img class="logo" src="${location.origin}/ldm-logo.png" alt="LDM Groupe"><p class="row name-row"><span class="label">${labels.name}</span><span class="value">${esc(button.dataset.name)}</span></p><p class="row"><span class="label">${labels.department}</span><span class="value">${esc(button.dataset.department)}</span></p><p class="row"><span class="label">${labels.meal}</span><span class="value">${esc(button.dataset.meal)}</span></p><p class="row"><span class="label">${labels.time}</span><span class="value">${esc(time)}</span></p></main><script>window.onload=()=>{window.print();window.onafterprint=()=>window.close()}<\/script></body></html>`);
  popup.document.close();
});

// Admin filter forms: apply as soon as a choice changes (the Apply button stays for no-JS).
document.querySelectorAll('form[data-autosubmit]').forEach((form) => {
  form.addEventListener('change', () => form.requestSubmit());
});

// Admin notify page: live preview, the user search and the recipient count.
document.querySelectorAll('[data-notify-form]').forEach((form) => {
  const preview = (input, out) => {
    if (!input || !out) return;
    const show = () => (out.textContent = input.value.trim() || out.dataset.empty);
    input.addEventListener('input', show);
  };
  preview(form.querySelector('[data-preview-title]'), document.querySelector('[data-preview-title-out]'));
  preview(form.querySelector('[data-preview-message]'), document.querySelector('[data-preview-message-out]'));

  const search = form.querySelector('[data-user-filter]');
  const select = form.querySelector('[data-user-select]');
  if (search && select) {
    search.addEventListener('input', () => {
      const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
      let first = null;
      select.querySelectorAll('option').forEach((option) => {
        const match = words.every((word) => option.dataset.text.includes(word));
        option.hidden = option.disabled = !match;
        if (match && !first) first = option;
      });
      if (select.selectedOptions[0]?.hidden && first) select.value = first.value;
      update();
    });
  }

  const line = form.querySelector('[data-recipient-line]');
  const update = () => {
    const audience = form.querySelector('input[name="audience"]:checked')?.value;
    let count = null;
    if (audience === 'everyone') count = Number(form.dataset.everyone);
    if (audience === 'department') count = Number(form.getAttribute(`data-dept-${form.querySelector('[name="department"]').value}`) ?? 0);
    if (audience === 'user') count = select && !select.selectedOptions[0]?.hidden && select.value ? 1 : 0;
    if (!line || count === null) return;
    line.replaceChildren();
    const number = document.createElement('span');
    number.className = 'font-medium';
    number.textContent = String(count);
    const rest = document.createElement('span');
    rest.className = 'text-mute';
    rest.textContent = ` ${count === 1 ? form.dataset.recipient : form.dataset.recipients}`;
    line.append(number, rest);
  };
  form.addEventListener('change', update);
  update();
});

// Statistics: "Print kitchen sheet" prints only the kitchen section.
document.querySelectorAll('[data-print-kitchen]').forEach((button) =>
  button.addEventListener('click', () => {
    document.body.dataset.print = 'kitchen';
    window.print();
    delete document.body.dataset.print;
  }),
);
