/* Mealboard control panel (no build step, no libraries). */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const csrf = () => ($('meta[name="csrf"]') || {}).content || '';

  async function api(action, body = {}) {
    const res = await fetch('?a=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf() },
      body: JSON.stringify(body),
    });
    let data = null;
    try { data = await res.json(); } catch (e) { /* not JSON */ }
    if (res.status === 401 && action !== 'login') { location.reload(); }
    if (!res.ok) {
      const err = new Error((data && data.message) || ('HTTP ' + res.status));
      err.status = res.status;
      throw err;
    }
    return data;
  }

  function toast(text, ok = true) {
    const el = document.createElement('div');
    el.className = 'toast ' + (ok ? 'ok' : 'fail');
    el.textContent = text;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4000);
  }

  function show(outId, data, ok = true) {
    const out = document.getElementById(outId);
    if (!out) return;
    out.hidden = false;
    out.className = 'out ' + (ok ? 'ok' : 'fail');
    out.textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
  }

  /* Table from a list of objects. actions: [{label, cls, fn(row)}] */
  function table(rows, opts = {}) {
    if (!rows || rows.length === 0) return '<p class="muted">Nothing here.</p>';
    const cols = opts.columns || Object.keys(rows[0]);
    const acts = opts.actions || [];
    let html = '<table><thead><tr>' + cols.map((c) => '<th>' + esc(c) + '</th>').join('') + (acts.length ? '<th></th>' : '') + '</tr></thead><tbody>';
    rows.forEach((r, i) => {
      html += '<tr' + (opts.rowClass ? ' class="' + esc(opts.rowClass(r)) + '"' : '') + '>';
      cols.forEach((c) => {
        let v = r[c];
        if (v !== null && typeof v === 'object') v = JSON.stringify(v);
        if (typeof v === 'boolean') v = v ? '✔' : '';
        const s = String(v ?? '');
        html += '<td title="' + esc(s.length > 60 ? s : '') + '">' + esc(s.length > 120 ? s.slice(0, 120) + '…' : s) + '</td>';
      });
      if (acts.length) {
        html += '<td class="acts">' + acts.map((a, j) => '<button class="small ' + (a.cls || '') + '" data-row="' + i + '" data-act="' + j + '">' + esc(a.label) + '</button>').join('') + '</td>';
      }
      html += '</tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function mountTable(el, rows, opts = {}) {
    el.innerHTML = table(rows, opts);
    $$('button[data-act]', el).forEach((b) => {
      b.addEventListener('click', () => opts.actions[+b.dataset.act].fn(rows[+b.dataset.row], b));
    });
  }

  const badge = (status) => '<span class="badge ' + esc(status) + '">' + esc(status) + '</span>';

  /* ------------------------------- theme ------------------------------- */

  // Auto (follows Windows) -> Light -> Dark -> Auto. Saved in this browser only.
  const THEMES = ['auto', 'light', 'dark'];
  const THEME_ICON = { auto: 'ri-auto', light: 'ri-sun', dark: 'ri-moon' };
  const THEME_LABEL = { auto: 'Theme: automatic (follows your PC)', light: 'Theme: light', dark: 'Theme: dark' };
  function currentTheme() {
    try { const t = localStorage.getItem('ctl-theme'); return THEMES.includes(t) ? t : 'auto'; } catch (e) { return 'auto'; }
  }
  function applyTheme(t) {
    if (t === 'auto') delete document.documentElement.dataset.theme; else document.documentElement.dataset.theme = t;
    $$('[data-theme-toggle]').forEach((b) => {
      b.title = THEME_LABEL[t] + ' - click to change';
      b.innerHTML = '<i class="ri ' + THEME_ICON[t] + '"></i>';
    });
  }
  applyTheme(currentTheme());
  $$('[data-theme-toggle]').forEach((b) => b.addEventListener('click', () => {
    const next = THEMES[(THEMES.indexOf(currentTheme()) + 1) % THEMES.length];
    try { localStorage.setItem('ctl-theme', next); } catch (e) { /* ignore */ }
    applyTheme(next);
    toast(THEME_LABEL[next]);
  }));

  /* ------------------------------- login ------------------------------- */

  const loginForm = $('#login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const err = $('#login-error');
      err.hidden = true;
      try {
        await api('login', { login: loginForm.login.value, password: loginForm.password.value });
        location.reload();
      } catch (ex) {
        err.textContent = ex.message;
        err.hidden = false;
      }
    });
    return;
  }

  $('#logout').addEventListener('click', async () => {
    try { await api('logout'); } catch (e) { /* ignore */ }
    location.reload();
  });

  /* -------------------------------- tabs ------------------------------- */

  const loaders = {};
  let current = 'health';

  function openTab(name) {
    current = name;
    $$('#tabs button').forEach((b) => b.classList.toggle('active', b.dataset.tab === name));
    $$('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== name; });
    document.body.classList.toggle('tab-tables', name === 'tables');
    document.documentElement.style.setProperty('--top-h', $('header.top').offsetHeight + 'px');
    try { localStorage.setItem('ctl-tab', name); } catch (e) { /* ignore */ }
    if (loaders[name]) loaders[name]().catch((e) => toast(e.message, false));
  }

  $$('#tabs button').forEach((b) => b.addEventListener('click', () => openTab(b.dataset.tab)));
  $$('[data-run]').forEach((b) => b.addEventListener('click', () => loaders[b.dataset.run]().catch((e) => toast(e.message, false))));

  /* ------------------------------- health ------------------------------ */

  /* Bento layout: a summary tile, 8 key numbers, then one compact tile per group. */
  const GROUP_LAYOUT = {
    Server: { span: 's6', icon: 'ri-server', cols: 2 },
    Database: { span: 's3', icon: 'ri-db' },
    Files: { span: 's3', icon: 'ri-folder' },
    Cron: { span: 's4', icon: 'ri-time' },
    Backups: { span: 's4', icon: 'ri-archive' },
    'E-mail': { span: 's4', icon: 'ri-mail' },
    Doors: { span: 's4', icon: 'ri-door' },
  };
  const RANK = { fail: 3, warn: 2, ok: 1, info: 0 };
  const worst = (list) => list.reduce((w, c) => (RANK[c.status] > RANK[w] ? c.status : w), 'info');
  const dot = (s) => '<span class="dot ' + esc(s) + '"></span>';

  function metricTiles(checks) {
    const find = (name, group) => checks.find((c) => c.name === name && (!group || c.group === group));
    const tiles = [];
    const add = (icon, label, check, value, sub) => tiles.push({ icon, label, status: check ? check.status : 'info', value, sub, title: check ? check.detail : '' });

    const cron = find('Last run', 'Cron');
    if (cron) {
      const m = cron.detail.match(/^(.*?ago)\s*\((\w+)/);
      add('ri-time', 'Last cron run', cron, m ? m[1] : 'Never', m ? 'started by ' + m[2] : 'not scheduled');
    }
    const backup = find('Last database backup');
    if (backup) {
      const parts = backup.detail.split(', ');
      add('ri-archive', 'Last backup', backup, parts.length >= 3 ? parts[1] : 'None', parts.length >= 3 ? parts[2] : backup.detail);
    }
    const queue = find('Queue', 'E-mail');
    if (queue) {
      const m = queue.detail.match(/(\d+) waiting, (\d+) failing/);
      add('ri-mail', 'E-mail queue', queue, m ? m[1] : '?', m ? (m[2] === '0' ? 'waiting · none failing' : m[2] + ' failing') : '');
    }
    const daily = find('Automatic daily list');
    if (daily) {
      const m = daily.detail.match(/at (\d{1,2}:00)/);
      add('ri-calendar', 'Daily list', daily, m ? m[1] : 'Off', m ? (daily.detail.split(' - ')[1] || '') : 'not sent automatically');
    }
    const db = find('Connection', 'Database');
    if (db) {
      const m = db.detail.match(/^(MySQL [\d.]+) \/ database (.+)$/);
      add('ri-db', 'Database', db, db.status === 'fail' ? 'Down' : (m ? m[1] : 'OK'), m ? m[2] : db.detail);
    }
    const people = find('People');
    if (people) {
      const m = people.detail.match(/(\d+) active, (\d+)/);
      add('ri-user', 'Active people', people, m ? m[1] : '?', m ? m[2] + ' super admin' + (m[2] === '1' ? '' : 's') : '');
    }
    const today = find('Bookings today');
    if (today) {
      const m = today.detail.match(/^(\d+).*for (.+)$/);
      add('ri-pulse', 'Bookings today', today, m ? m[1] : '?', m ? m[2] : '');
    }
    const doors = checks.filter((c) => c.group === 'Doors');
    const real = doors.filter((c) => c.name !== 'Door PCs');
    tiles.push({
      icon: 'ri-door', label: 'Door PCs', status: real.length ? worst(real) : 'info',
      value: String(real.length), sub: real.length ? 'seen in the last 3 days' : 'none seen in 3 days', title: doors.map((d) => d.detail).join('\n'),
    });
    return tiles;
  }

  function checkRow(c) {
    const problem = c.status === 'fail' || c.status === 'warn';
    return '<li class="' + esc(c.status) + '" title="' + esc(c.name + ': ' + c.detail) + '">' + dot(c.status) +
      '<span class="n">' + esc(c.name) + '</span><span class="d">' + esc(c.detail) + '</span>' +
      (problem && c.fix ? '<span class="fx"><i class="ri ri-right"></i>' + esc(c.fix) + '</span>' : '') + '</li>';
  }

  loaders.health = async () => {
    const bento = $('#bento');
    bento.classList.add('loading');
    const { checks } = await api('health');
    bento.classList.remove('loading');
    $('#health-time').textContent = 'checked ' + new Date().toLocaleTimeString();

    const counts = { ok: 0, warn: 0, fail: 0, info: 0 };
    checks.forEach((c) => { counts[c.status] = (counts[c.status] || 0) + 1; });
    const overall = counts.fail ? 'fail' : (counts.warn ? 'warn' : 'ok');
    const headline = counts.fail ? counts.fail + ' thing' + (counts.fail > 1 ? 's' : '') + ' broken'
      : counts.warn ? counts.warn + ' warning' + (counts.warn > 1 ? 's' : '') : 'Everything works';
    const problems = checks.filter((c) => c.status === 'fail' || c.status === 'warn');

    let html = '<div class="tile s4 r2 hero ' + overall + '">' +
      '<div class="hero-icon"><i class="ri ' + (overall === 'ok' ? 'ri-ok' : overall === 'warn' ? 'ri-warn' : 'ri-fail') + '"></i></div>' +
      '<div class="hero-title">' + esc(headline) + '</div>' +
      '<div class="hero-counts"><span class="ok"><b>' + counts.ok + '</b> working</span><span class="warn"><b>' + counts.warn + '</b> warnings</span><span class="fail"><b>' + counts.fail + '</b> broken</span><span><b>' + counts.info + '</b> info</span></div>' +
      (problems.length ? '<ul class="hero-list">' + problems.slice(0, 4).map((c) => '<li>' + dot(c.status) + '<span><b>' + esc(c.name) + '</b> ' + esc(c.fix || c.detail) + '</span></li>').join('') + '</ul>'
        : '<p class="hint">All ' + checks.length + ' checks passed. The cron, backups and e-mail are running.</p>') +
      '</div>';

    html += metricTiles(checks).map((t) =>
      '<div class="tile s2 metric ' + esc(t.status) + '" title="' + esc(t.title) + '">' +
      '<div class="m-head"><i class="ri ' + t.icon + '"></i><span class="lbl">' + esc(t.label) + '</span>' + dot(t.status) + '</div>' +
      '<div class="m-value">' + esc(t.value) + '</div><div class="m-sub">' + esc(t.sub) + '</div></div>').join('');

    const groups = {};
    checks.forEach((c) => { (groups[c.group] = groups[c.group] || []).push(c); });
    Object.keys(groups).forEach((g) => {
      const list = groups[g];
      const lay = GROUP_LAYOUT[g] || { span: 's4', icon: 'ri-info' };
      const bad = list.filter((c) => c.status === 'fail' || c.status === 'warn').length;
      html += '<div class="tile ' + lay.span + ' group">' +
        '<div class="g-head"><i class="ri ' + lay.icon + '"></i><span>' + esc(g) + '</span>' +
        '<span class="g-count ' + worst(list) + '">' + (bad ? bad + ' issue' + (bad > 1 ? 's' : '') : (list.some((c) => c.status === 'ok') ? 'all ok' : 'info')) + '</span></div>' +
        '<ul class="rows' + (lay.cols === 2 ? ' two' : '') + '">' + list.map(checkRow).join('') + '</ul></div>';
    });

    html += '<div class="tile s4 group"><div class="g-head"><i class="ri ri-code"></i><span>API from the browser</span><span class="g-count info" id="api-count">…</span></div><ul class="rows" id="api-checks"></ul></div>';
    html += '<div class="tile s4 group"><div class="g-head"><i class="ri ri-flask"></i><span>Logic tests</span><span class="grow"></span>' +
      '<button type="button" class="small" id="run-logic"><i class="ri ri-refresh"></i> Run</button></div>' +
      '<p class="hint">Runs <code>php artisan test</code> (about 165 tests, ~1 min) on the test database <code>mealboard_test</code>. Changes nothing in the real data.</p>' +
      '<div id="logic-result" class="logic-result"></div></div>';

    bento.innerHTML = html;
    $('#run-logic').addEventListener('click', runLogicTests);
    apiChecks();
  };

  async function runLogicTests() {
    const box = $('#logic-result');
    box.innerHTML = '<span class="hint">Running…</span>';
    try {
      const r = await act('logic_tests', {}, null, $('#run-logic'));
      const m = String(r.output).match(/(\d+) passed, (\d+) failed/);
      box.innerHTML = '<div class="logic-big ' + (r.ok ? 'ok' : 'fail') + '">' + dot(r.ok ? 'ok' : 'fail') +
        (m ? '<b>' + m[1] + '</b> passed · <b>' + m[2] + '</b> failed' : (r.ok ? 'Passed' : 'Failed')) + '<span class="hint"> in ' + r.seconds + ' s</span></div>' +
        (r.ok ? '' : '<pre class="out fail">' + esc(r.output) + '</pre>');
    } catch (e) {
      box.innerHTML = '<div class="logic-big fail">' + dot('fail') + esc(e.message) + '</div>';
    }
  }

  async function apiChecks() {
    const tests = [
      ['GET /api/booking-rules', '../api/booking-rules', (s) => s === 200],
      ['GET /api/menu', '../api/menu', (s) => s === 200],
      ['Sign-in required (→ 401)', '../api/users', (s) => s === 401],
      ['Cron needs its key (→ 403)', '../api/cron-run', (s) => s === 403],
      ['Unknown path (→ 404)', '../api/does-not-exist', (s) => s === 404],
    ];
    const el = $('#api-checks');
    el.innerHTML = tests.map((t, i) => '<li id="apic-' + i + '">' + dot('info') + '<span class="n">' + esc(t[0]) + '</span><span class="d">…</span></li>').join('');
    let okCount = 0;
    await Promise.all(tests.map(async ([name, url, good], i) => {
      const li = document.getElementById('apic-' + i);
      const started = performance.now();
      let status = 0;
      let note = '';
      try {
        const r = await fetch(url, { headers: { Accept: 'application/json' } });
        status = r.status;
        const type = r.headers.get('content-type') || '';
        if (!type.includes('json')) note = ' - not JSON (rewrite rules?)';
      } catch (e) {
        note = ' - ' + e.message;
      }
      const ms = Math.round(performance.now() - started);
      const st = good(status) && !note ? 'ok' : 'fail';
      if (st === 'ok') okCount++;
      li.className = st;
      li.querySelector('.dot').className = 'dot ' + st;
      li.querySelector('.d').textContent = 'HTTP ' + status + ' · ' + ms + ' ms' + note;
    }));
    const count = $('#api-count');
    if (count) {
      count.textContent = okCount + '/' + tests.length + ' ok';
      count.className = 'g-count ' + (okCount === tests.length ? 'ok' : 'fail');
    }
  }

  /* -------------------------------- cron ------------------------------- */

  const stateLabel = { ready: 'ready', done: 'ok', wait: 'info', blocked: 'warn', skip: 'info', off: 'info' };

  async function loadPreview(at) {
    const p = await api('cron_preview', at ? { at } : {});
    $('#preview').innerHTML =
      '<p>Time used: <b>' + esc(p.now) + '</b>' + (p.simulated ? ' (simulated)' : '') +
      ' · auto no-show from: <b>' + esc(p.autoNoShowFrom || 'off') + '</b>' + (p.ramadan ? ' · Ramadan on' : '') + '</p>' +
      '<table><thead><tr><th>Day</th><th>Meal</th><th>Booked</th><th>Served</th><th>Missed</th><th>Open</th><th>Ends</th><th>State</th><th>Why</th></tr></thead><tbody>' +
      p.items.map((i) => '<tr><td>' + esc(i.day) + '</td><td>' + esc(i.meal) + '</td><td>' + i.booked + '</td><td>' + i.served + '</td><td>' + i.missed +
        '</td><td>' + i.open + '</td><td>' + esc(i.endsAt) + '</td><td><span class="badge ' + (stateLabel[i.state] || 'info') + '">' + esc(i.state) + '</span></td><td>' + esc(i.why) + '</td></tr>').join('') +
      '</tbody></table>';
    return p;
  }

  loaders.cron = async () => {
    const [s] = await Promise.all([api('cron_state'), loadPreview($('#preview-form').at.value)]);
    $('#cli-cron').textContent = s.cliCron;
    $('#cli-backup').textContent = s.cliBackup;
    $('#cron-url').textContent = location.origin + s.cronUrl;

    mountTable($('#cron-log'), s.log.map((e) => ({
      when: e.atText,
      source: e.source,
      ms: e.ms,
      result: e.ok ? 'ok' : 'FAILED',
      'no-shows': (e.result.autoNoShow && e.result.autoNoShow.status === 'ran')
        ? (e.result.autoNoShow.marked.map((m) => m.day + ' ' + m.meal + ': ' + m.marked).join(', ') || 'nothing to do')
        : (e.result.autoNoShow ? e.result.autoNoShow.status : ''),
      'e-mails': e.result.emails ? e.result.emails.sent + ' sent, ' + e.result.emails.failed + ' failed' : '',
      errors: (e.result.errors || []).join(' | '),
    })), { rowClass: (r) => (r.result === 'ok' ? '' : 'bad') });

    mountTable($('#marked'), s.marked.map((m) => ({ day: m.day, meal: m.meal, at: new Date(+m.at).toLocaleString(), marked: m.marked })), {
      actions: [{ label: 'Delete', cls: 'danger', fn: async (r) => {
        if (!confirm('Let the cron process ' + r.day + ' ' + r.meal + ' again?')) return;
        await act('table_delete', { table: '_mealboard_marked', id: r.day + '|' + r.meal });
        loaders.cron();
      } }],
    });

    const d = s.daily.settings;
    $('#daily').innerHTML = '<p>Automatic: <b>' + (d.auto ? 'on' : 'off') + '</b>' + (d.auto ? ', at ' + d.hour + ':00 for ' + esc(d.day) : '') +
      '<br>Recipients: ' + esc(d.recipients.join(', ') || 'none') + '</p>' + table(s.daily.last);
  };

  $('#preview-form').addEventListener('submit', (e) => {
    e.preventDefault();
    loadPreview(e.target.at.value).catch((ex) => toast(ex.message, false));
  });

  /* ------------------------------- inject ------------------------------ */

  let tableDefs = [];

  async function loadTables(fresh = false) {
    if (tableDefs.length && !fresh) return tableDefs;
    tableDefs = (await api('tables')).tables;
    return tableDefs;
  }

  loaders.inject = async () => {
    const [inj, defs] = await Promise.all([api('injected'), loadTables()]);
    $('#injected').textContent = 'Injected so far: ' + Object.entries(inj).map(([k, v]) => k + ' ' + v).join(', ');
    const sel = $('#row-table');
    if (!sel.options.length) {
      defs.filter((d) => !d.system).forEach((d) => sel.add(new Option(d.name, d.name)));
      sel.addEventListener('change', fillRowTemplate);
      fillRowTemplate();
    }
    const today = new Date().toISOString().slice(0, 10);
    $$('input[type="date"]').forEach((i) => { if (!i.value && i.name !== 'to') i.value = today; });
  };

  async function fillRowTemplate() {
    const { columns } = await api('table_columns', { table: $('#row-table').value });
    const sample = { text: '', bool: false, int: 0, float: 0, date: new Date().toISOString().slice(0, 10), json: [], password: '' };
    const obj = {};
    columns.filter((c) => c.writable).forEach((c) => { obj[c.name] = sample[c.type]; });
    $('#row-json').value = JSON.stringify(obj, null, 2);
    $('#row-columns').textContent = columns.filter((c) => c.writable).map((c) => c.name + ':' + c.type).join('  ');
  }

  $$('[data-toggle-who]').forEach((s) => s.addEventListener('change', () => {
    $('[data-who]', s.form).hidden = s.value !== 'user';
  }));

  /* ------------------------------- tables ------------------------------ */

  // Friendlier names for the old table names.
  const TABLE_NAMES = {
    user: 'People', oder: 'Bookings', meun: 'Menu', food: 'Food', sandwich_orders: 'Sandwiches',
    special_meals: 'Special meals', manager_meals: 'Manager meals', meal_groups: 'Meal groups',
    department_rules: 'Department rules', booking_rules: 'Booking rules', price_of_meal: 'Prices',
    record_penalties: 'Penalties', notifications: 'Notifications', reports: 'Reports', smtp: 'SMTP',
    pending_emails: 'E-mail queue', _mealboard_doors: 'Door PCs', _mealboard_marked: 'Auto no-show runs',
    _mealboard_daily_sent: 'Daily list sends', _mealboard_settings: 'Settings (system)', login_attempts: 'Login attempts',
  };
  const PERSON_COLUMNS = ['user', 'sent_by', 'switched_by'];
  // What the cryptic column names mean (shown under the column name).
  const COLUMN_HINTS = { oder: { bool: 'breakfast booked', bool2: 'lunch booked', bool3: 'dinner booked' } };
  const hintOf = (col) => (COLUMN_HINTS[tb.table] || {})[col] || '';
  const ROLES = ['employee', 'intern', 'responsible', 'manager', 'admin', 'super_admin', 'door'];
  const tb = { table: null, page: 1, perPage: 30, sort: '', dir: 'desc', search: '', data: null, selected: new Set() };

  function tableLabel(name) { return TABLE_NAMES[name] || name; }

  function renderTableList() {
    const q = $('#tb-filter').value.trim().toLowerCase();
    const match = (d) => !q || d.name.toLowerCase().includes(q) || tableLabel(d.name).toLowerCase().includes(q);
    const item = (d) => '<button type="button" class="tb-item' + (d.name === tb.table ? ' active' : '') + '" data-table="' + esc(d.name) + '">' +
      '<i class="ri ' + (d.system ? 'ri-lock' : 'ri-table') + '"></i><span class="txt">' + esc(tableLabel(d.name)) +
      (tableLabel(d.name) !== d.name ? '<small>' + esc(d.name) + '</small>' : '') + '</span><span class="cnt">' + d.count + '</span></button>';
    const app = tableDefs.filter((d) => !d.system && match(d));
    const sys = tableDefs.filter((d) => d.system && match(d));
    $('#tb-list').innerHTML =
      (app.length ? '<div class="tb-group">App data</div>' + app.map(item).join('') : '') +
      (sys.length ? '<div class="tb-group">System</div>' + sys.map(item).join('') : '') +
      (!app.length && !sys.length ? '<p class="hint pad">No table matches.</p>' : '');
    $$('#tb-list [data-table]').forEach((b) => b.addEventListener('click', () => selectTable(b.dataset.table)));
  }

  function selectTable(name) {
    tb.table = name; tb.page = 1; tb.sort = ''; tb.dir = 'desc'; tb.search = ''; tb.selected.clear();
    $('#tb-search').q.value = '';
    try { localStorage.setItem('ctl-table', name); } catch (e) { /* ignore */ }
    renderTableList();
    loadRows().catch((e) => toast(e.message, false));
  }

  loaders.tables = async () => {
    await loadTables(true);
    if (!tb.table) {
      let saved = null;
      try { saved = localStorage.getItem('ctl-table'); } catch (e) { /* ignore */ }
      tb.table = tableDefs.some((d) => d.name === saved) ? saved : tableDefs[0].name;
    }
    renderTableList();
    await loadRows();
  };

  async function loadRows() {
    $('#tb-table').classList.add('loading');
    const d = await api('table_rows', { table: tb.table, page: tb.page, perPage: tb.perPage, sort: tb.sort, dir: tb.dir, search: tb.search });
    tb.data = d;
    tb.page = d.page;
    $('#tb-table').classList.remove('loading');
    $('#tb-title').textContent = tableLabel(d.table);
    $('#tb-new').hidden = !d.editable;
    $('#tb-clear').hidden = !tb.search;
    $('#tb-total').textContent = 'Total found: ' + d.total;
    $('#tb-page').textContent = 'Page ' + d.page + ' / ' + d.pages;
    $('#tb-prev').disabled = d.page <= 1;
    $('#tb-next').disabled = d.page >= d.pages;
    const entry = tableDefs.find((t) => t.name === d.table);
    if (entry && !tb.search && entry.count !== d.total) { entry.count = d.total; renderTableList(); }
    renderRecords();
  }

  /* One cell, PocketBase style: labels for ids/booleans, "N/A" for empty values. */
  function cellHtml(col, value, row) {
    const d = tb.data;
    if (value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
      return '<span class="na">N/A</span>';
    }
    if (col.name === 'id') return '<span class="tag mono">' + esc(value) + '</span>';
    if (col.type === 'bool') return value === true || value === 1 || value === '1' ? '<span class="tag yes">True</span>' : '<span class="tag">False</span>';
    if (col.type === 'date' && typeof value === 'string') {
      const v = value.replace('T', ' ').replace(/\.\d+Z?$/, '').replace(/Z$/, '');
      return '<span class="date">' + esc(v.endsWith(' 12:00:00') ? v.slice(0, 10) : v) + '</span>';
    }
    if (col.type === 'json' || typeof value === 'object') {
      const s = JSON.stringify(value);
      return '<span class="mono small">' + esc(s.length > 60 ? s.slice(0, 60) + '…' : s) + '</span>';
    }
    if (PERSON_COLUMNS.includes(col.name) && d.people[value]) {
      return '<span class="person">' + esc(d.people[value]) + '<small>' + esc(value) + '</small></span>';
    }
    // Millisecond timestamps of the system tables.
    if (/^(last_seen|oldest_pending|at|tried_at|sent_at)$/.test(col.name) && /^\d{12,}$/.test(String(value))) {
      return '<span class="date">' + esc(new Date(+value).toLocaleString()) + '</span>';
    }
    const s = String(value);
    return '<span class="txt" title="' + esc(s.length > 60 ? s : '') + '">' + esc(s.length > 70 ? s.slice(0, 70) + '…' : s) + '</span>';
  }

  function renderRecords() {
    const d = tb.data;
    const cols = d.columns.filter((c) => c.type !== 'password');
    const idCol = cols.find((c) => c.name === 'id');
    const ordered = idCol ? [idCol, ...cols.filter((c) => c !== idCol)] : cols;
    const allOn = d.rows.length > 0 && d.rows.every((r) => tb.selected.has(r._key));
    const arrow = (c) => (tb.sort === c.name ? '<i class="ri ' + (tb.dir === 'asc' ? 'ri-up' : 'ri-down') + '"></i>' : '');

    let html = '<thead><tr>' +
      (d.deletable ? '<th class="col-check"><input type="checkbox" id="tb-all"' + (allOn ? ' checked' : '') + ' title="Select all on this page"></th>' : '') +
      ordered.map((c) => '<th data-sort="' + esc(c.name) + '" class="sortable' + (tb.sort === c.name ? ' sorted' : '') + '"><span>' + esc(c.name) + '</span>' + arrow(c) + (hintOf(c.name) ? '<small>' + esc(hintOf(c.name)) + '</small>' : '') + '</th>').join('') +
      '<th class="col-open"></th></tr></thead><tbody>';
    if (d.rows.length === 0) {
      html += '<tr class="empty"><td colspan="' + (ordered.length + 2) + '">' +
        (tb.search ? 'No records match "' + esc(tb.search) + '".' : 'No records yet.') + '</td></tr>';
    }
    d.rows.forEach((r, i) => {
      html += '<tr data-i="' + i + '"' + (tb.selected.has(r._key) ? ' class="selected"' : '') + '>' +
        (d.deletable ? '<td class="col-check"><input type="checkbox" data-key="' + esc(r._key) + '"' + (tb.selected.has(r._key) ? ' checked' : '') + '></td>' : '') +
        ordered.map((c) => '<td>' + cellHtml(c, r[c.name], r) + '</td>').join('') +
        '<td class="col-open"><i class="ri ri-right"></i></td></tr>';
    });
    html += '</tbody>';
    const t = $('#tb-table');
    t.innerHTML = html;

    $$('th[data-sort]', t).forEach((th) => th.addEventListener('click', () => {
      const c = th.dataset.sort;
      if (tb.sort === c) tb.dir = tb.dir === 'asc' ? 'desc' : 'asc'; else { tb.sort = c; tb.dir = 'asc'; }
      tb.page = 1;
      loadRows().catch((e) => toast(e.message, false));
    }));
    const all = $('#tb-all', t);
    if (all) all.addEventListener('change', () => {
      d.rows.forEach((r) => (all.checked ? tb.selected.add(r._key) : tb.selected.delete(r._key)));
      renderRecords();
    });
    $$('td.col-check input', t).forEach((cb) => {
      cb.addEventListener('click', (e) => e.stopPropagation());
      cb.addEventListener('change', () => {
        cb.checked ? tb.selected.add(cb.dataset.key) : tb.selected.delete(cb.dataset.key);
        renderRecords();
      });
    });
    $$('td.col-check', t).forEach((td) => td.addEventListener('click', (e) => e.stopPropagation()));
    $$('tbody tr[data-i]', t).forEach((tr) => tr.addEventListener('click', () => openRecord(d.rows[+tr.dataset.i])));

    $('#tb-bulk').hidden = tb.selected.size === 0;
    $('#tb-count').textContent = tb.selected.size;
  }

  /* ---------------------------- record panel ---------------------------- */

  let recRow = null;

  function fieldHtml(c, value, isNew, readOnly) {
    const name = esc(c.name);
    const ro = readOnly || !c.writable;
    const label = '<label for="f-' + name + '">' + name + ' <span class="type">' + esc(c.type) + '</span>' + (ro && !readOnly ? ' <span class="type">read only</span>' : '') + '</label>';
    const dis = ro ? ' disabled' : '';
    let input;
    if (c.type === 'password') {
      input = '<input type="password" id="f-' + name + '" name="' + name + '" autocomplete="new-password" placeholder="' +
        (isNew ? 'Empty = default password (MEALBOARD_DEFAULT_PASSWORD)' : 'Leave empty to keep the current password') + '"' + dis + '>';
    } else if (c.type === 'bool') {
      const on = value === true || value === 1 || value === '1';
      return '<div class="field field-bool"><label class="switch"><input type="checkbox" id="f-' + name + '" name="' + name + '"' + (on ? ' checked' : '') + dis + '><span class="slider"></span><span>' + name +
        (hintOf(c.name) ? ' <span class="type">' + esc(hintOf(c.name)) + '</span>' : '') + (ro && !readOnly ? ' <span class="type">read only</span>' : '') + '</span></label></div>';
    } else if (c.type === 'int' || c.type === 'float') {
      input = '<input type="number" step="' + (c.type === 'float' ? 'any' : '1') + '" id="f-' + name + '" name="' + name + '" value="' + esc(value ?? '') + '"' + dis + '>';
    } else if (c.type === 'date' && c.writable) {
      input = '<input type="date" id="f-' + name + '" name="' + name + '" value="' + esc(String(value || '').slice(0, 10)) + '"' + dis + '>';
    } else if (c.type === 'json' || (value !== null && typeof value === 'object')) {
      input = '<textarea class="mono" rows="4" id="f-' + name + '" name="' + name + '"' + dis + '>' + esc(JSON.stringify(value ?? [], null, 2)) + '</textarea>';
    } else if (c.name === 'User_Type' && !ro) {
      const v = String(value || 'employee');
      const opts = ROLES.includes(v) ? ROLES : [v, ...ROLES];
      input = '<select id="f-' + name + '" name="' + name + '">' + opts.map((o) => '<option' + (o === v ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>';
    } else if (c.long || String(value ?? '').length > 80 || String(value ?? '').includes('\n')) {
      input = '<textarea rows="3" id="f-' + name + '" name="' + name + '"' + dis + '>' + esc(value ?? '') + '</textarea>';
    } else {
      input = '<input id="f-' + name + '" name="' + name + '" value="' + esc(value ?? '') + '"' + dis + '>';
    }
    let help = '';
    if (PERSON_COLUMNS.includes(c.name)) {
      help = '<div class="help">Person id' + (value && tb.data.people[value] ? ': ' + esc(tb.data.people[value]) : '') + '</div>';
    }
    return '<div class="field">' + label + input + help + '</div>';
  }

  function openRecord(row) {
    const d = tb.data;
    const isNew = !row;
    recRow = row || null;
    const readOnly = !d.editable;
    $('#rec-title').textContent = (isNew ? 'New ' : (readOnly ? 'View ' : 'Edit ')) + tableLabel(d.table) + ' record';

    let html = '';
    if (!isNew) {
      const meta = ['id', 'created', 'updated'].filter((k) => row[k] !== undefined);
      if (meta.length) {
        html += '<div class="rec-meta">' + meta.map((k) => '<div><span class="hint">' + k + '</span>' +
          (k === 'id' ? '<span class="tag mono copy" title="Copy" data-copy="' + esc(row.id) + '">' + esc(row.id) + ' <i class="ri ri-copy"></i></span>' : '<span>' + cellHtml({ name: k, type: 'date' }, row[k]) + '</span>') + '</div>').join('') + '</div>';
      }
    }
    d.columns.forEach((c) => {
      if (['id', 'created', 'updated'].includes(c.name)) return;
      if (isNew && !c.writable) return;
      const fresh = c.type === 'bool' ? (d.table === 'user' && c.name === 'Status') : (d.table === 'user' && c.name === 'User_Type' ? 'employee' : '');
      html += fieldHtml(c, isNew ? fresh : row[c.name], isNew, readOnly);
    });
    if (d.table === 'oder') html += '<p class="hint">Saving a booking with a missed meal adds a strike; removing it gives the strike back.</p>';

    const form = $('#rec-form');
    form.innerHTML = html;
    $$('[data-copy]', form).forEach((el) => el.addEventListener('click', () => {
      navigator.clipboard && navigator.clipboard.writeText(el.dataset.copy).then(() => toast('Copied'));
    }));
    $('#rec-save').hidden = readOnly;
    $('#rec-delete').hidden = isNew || !d.deletable;
    $('#rec').hidden = false;
    document.body.classList.add('no-scroll');
    const first = $('input:not([disabled]), textarea:not([disabled]), select:not([disabled])', form);
    if (first) first.focus();
  }

  function closeRecord() {
    $('#rec').hidden = true;
    document.body.classList.remove('no-scroll');
    recRow = null;
  }

  $$('[data-rec-close]').forEach((b) => b.addEventListener('click', closeRecord));
  $('#rec').addEventListener('click', (e) => { if (e.target.id === 'rec') closeRecord(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#rec').hidden) closeRecord(); });

  $('#rec-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = tb.data;
    const data = {};
    for (const c of d.columns) {
      if (!c.writable) continue;
      const el = $('[name="' + CSS.escape(c.name) + '"]', e.target);
      if (!el) continue;
      let v;
      if (c.type === 'bool') v = el.checked;
      else if (c.type === 'json') {
        try { v = el.value.trim() === '' ? [] : JSON.parse(el.value); } catch (ex) { toast(c.name + ': not valid JSON', false); el.focus(); return; }
      } else v = el.value;
      if (recRow) {
        // Only send what changed.
        const before = recRow[c.name];
        const same = c.type === 'bool' ? !!before === v
          : c.type === 'json' ? JSON.stringify(before ?? []) === JSON.stringify(v)
          : c.type === 'date' ? String(before || '').slice(0, 10) === v
          : c.type === 'password' ? v === ''
          : String(before ?? '') === v;
        if (same) continue;
      } else if (v === '' && c.type !== 'text') continue;
      data[c.name] = v;
    }
    if (recRow && Object.keys(data).length === 0) { toast('Nothing changed'); return; }
    try {
      await act('table_save', { table: d.table, id: recRow ? recRow.id : '', data }, null, $('#rec-save'));
      closeRecord();
      await loadRows();
    } catch (ex) { /* shown */ }
  });

  $('#rec-delete').addEventListener('click', async () => {
    if (!recRow || !confirm('Delete this record? This cannot be undone.')) return;
    try {
      await act('table_delete', { table: tb.data.table, ids: [recRow._key] }, null, $('#rec-delete'));
      tb.selected.delete(recRow._key);
      closeRecord();
      await loadRows();
    } catch (ex) { /* shown */ }
  });

  /* --------------------------- toolbar & paging -------------------------- */

  $('#tb-filter').addEventListener('input', renderTableList);
  $('#tb-refresh').addEventListener('click', () => loadRows().catch((e) => toast(e.message, false)));
  $('#tb-new').addEventListener('click', () => openRecord(null));
  $('#tb-search').addEventListener('submit', (e) => {
    e.preventDefault();
    tb.search = e.target.q.value.trim();
    tb.page = 1;
    loadRows().catch((ex) => toast(ex.message, false));
  });
  $('#tb-clear').addEventListener('click', () => {
    $('#tb-search').q.value = '';
    tb.search = '';
    tb.page = 1;
    loadRows().catch((e) => toast(e.message, false));
  });
  $('#tb-per').addEventListener('change', (e) => { tb.perPage = +e.target.value; tb.page = 1; loadRows().catch((ex) => toast(ex.message, false)); });
  $('#tb-prev').addEventListener('click', () => { tb.page--; loadRows().catch((e) => toast(e.message, false)); });
  $('#tb-next').addEventListener('click', () => { tb.page++; loadRows().catch((e) => toast(e.message, false)); });
  $('#tb-unselect').addEventListener('click', () => { tb.selected.clear(); renderRecords(); });
  $('#tb-bulk-delete').addEventListener('click', async () => {
    const n = tb.selected.size;
    if (!n || !confirm('Delete ' + n + ' selected record(s)? This cannot be undone.')) return;
    try {
      await act('table_delete', { table: tb.data.table, ids: Array.from(tb.selected) }, null, $('#tb-bulk-delete'));
      tb.selected.clear();
      await loadRows();
    } catch (e) { /* shown */ }
  });

  /* ---------------------------- e-mail & doors -------------------------- */

  loaders.email = async () => {
    const [mails, p] = await Promise.all([api('emails_list'), api('cron_preview')]);
    mountTable($('#email-rows'), mails.rows, {
      rowClass: (r) => (r.sent_at ? '' : (r.attempts > 0 ? 'bad' : 'pending')),
      actions: [
        { label: 'Retry', fn: async (r) => { await act('email_retry', { id: r.id }); await act('emails_flush'); loaders.email(); } },
        { label: 'Delete', cls: 'danger', fn: async (r) => { await act('table_delete', { table: 'pending_emails', id: r.id }); loaders.email(); } },
      ],
    });
    mountTable($('#doors'), p.doors, {
      actions: [{ label: 'Forget', cls: 'danger', fn: async (r) => {
        if (!confirm('Forget door ' + r.door + '? (it comes back on its next heartbeat)')) return;
        await act('table_delete', { table: '_mealboard_doors', id: r.door });
        loaders.email();
      } }],
    });
  };

  /* ------------------------------ activity ----------------------------- */

  loaders.activity = async () => {
    const { rows } = await api('activity');
    mountTable($('#activity-rows'), rows.map((r) => ({ when: r.atText, who: r.by, action: r.action, result: r.ok ? 'ok' : 'FAILED', details: r.summary })), {
      rowClass: (r) => (r.result === 'ok' ? '' : 'bad'),
    });
  };

  /* -------------------------- buttons and forms ------------------------- */

  async function act(action, body = {}, outId = null, btn = null) {
    if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Working…'; }
    try {
      const res = await api(action, body);
      if (outId) show(outId, res, res.ok !== false);
      toast(action.replace(/_/g, ' ') + ': done', res.ok !== false);
      return res;
    } catch (e) {
      if (outId) show(outId, 'ERROR: ' + e.message, false);
      toast(e.message, false);
      throw e;
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
    }
  }

  $$('button[data-action]').forEach((b) => b.addEventListener('click', async () => {
    if (b.dataset.confirm && !confirm(b.dataset.confirm)) return;
    try {
      await act(b.dataset.action, {}, b.dataset.out, b);
      if (loaders[current] && current !== 'health') loaders[current]();
    } catch (e) { /* shown */ }
  }));

  function formBody(form) {
    const body = {};
    const multi = {};
    $$('input, select, textarea', form).forEach((el) => {
      if (!el.name) return;
      if (el.type === 'checkbox') {
        const same = $$('input[type="checkbox"][name="' + el.name + '"]', form);
        if (same.length > 1) {
          multi[el.name] = multi[el.name] || [];
          if (el.checked) multi[el.name].push(el.value);
        } else {
          body[el.name] = el.checked;
        }
      } else {
        body[el.name] = el.value;
      }
    });
    return Object.assign(body, multi);
  }

  $$('form[data-form]').forEach((f) => f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = formBody(f);
    if (f.dataset.form === 'inject_row') {
      try { body.data = JSON.parse(body.data); } catch (ex) { toast('The row is not valid JSON: ' + ex.message, false); return; }
    }
    if (f.dataset.form === 'inject_door' && body.scope === 'all' && !confirm('This marks REAL people as served/missed and gives them strikes. Continue?')) return;
    try {
      await act(f.dataset.form, body, f.dataset.out, $('button[type="submit"]', f));
      tableDefs = [];
      if (loaders[current] && current !== 'health' && current !== 'email') loaders[current]();
      if (current === 'email') loaders.email();
    } catch (ex) { /* shown */ }
  }));

  /* -------------------------------- start ------------------------------ */

  let start = 'health';
  try { start = localStorage.getItem('ctl-tab') || 'health'; } catch (e) { /* ignore */ }
  openTab(loaders[start] ? start : 'health');
})();
