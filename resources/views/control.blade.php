<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf" content="{{ $csrf }}">
<title>Mealboard Control</title>
<script>
  // Apply the saved theme before the page draws (no white flash in dark mode).
  try { var t = localStorage.getItem('ctl-theme'); if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t; } catch (e) {}
</script>
<link rel="stylesheet" href="/control-assets/style.css?v={{ $version }}">
<link rel="stylesheet" href="/control-assets/tables.css?v={{ $version }}">
<link rel="stylesheet" href="/control-assets/bento.css?v={{ $version }}">
</head>
<body>
@if (!$signedIn)
<main class="login">
  <button type="button" class="icon-btn theme-btn login-theme" data-theme-toggle title="Theme"><i class="ri ri-auto"></i></button>
  <form id="login-form" class="card">
    <h1>Mealboard Control</h1>
    <p class="muted">Super admins only.</p>
    @if ($dbError)
      <p class="alert fail">Database error: {{ $dbError }}</p>
    @endif
    <label>E-mail or username<input name="login" autocomplete="username" required autofocus></label>
    <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
    <button class="primary" type="submit">Sign in</button>
    <p id="login-error" class="alert fail" hidden></p>
  </form>
</main>
@else
<header class="top">
  <strong>Mealboard Control</strong>
  <nav id="tabs">
    <button data-tab="health" class="active">Health</button>
    <button data-tab="cron">Cron</button>
    <button data-tab="inject">Inject data</button>
    <button data-tab="tables">Tables</button>
    <button data-tab="email">E-mail &amp; doors</button>
    <button data-tab="activity">Activity</button>
  </nav>
  <span class="who">
    <button type="button" class="icon-btn theme-btn" data-theme-toggle title="Theme"><i class="ri ri-auto"></i></button>
    {{ $name }} <button id="logout" class="link">Sign out</button>
  </span>
</header>
<main id="app">

<!-- HEALTH -->
<section data-panel="health">
  <div class="bar">
    <h2>What works and what doesn't</h2>
    <span class="hint" id="health-time"></span>
    <span class="grow"></span>
    <button class="primary" data-run="health"><i class="ri ri-refresh"></i> Re-check</button>
  </div>
  <div id="bento" class="bento"><div class="tile s12 hint">Checking…</div></div>
</section>

<!-- CRON -->
<section data-panel="cron" hidden>
  <div class="bar">
    <h2>Cron</h2>
    <button data-run="cron">Refresh</button>
  </div>
  <div class="grid2">
    <div class="card">
      <h3>Run jobs now</h3>
      <p class="muted">Same code as the scheduled cron. <b>Runs on real data</b>: it marks real no-shows when a meal is over.</p>
      <div class="buttons">
        <button class="primary" data-action="cron_run" data-out="cron-out" data-confirm="Run the full cron now on the real data?">Run full cron</button>
        <button data-action="emails_flush" data-out="cron-out">Send queued e-mails</button>
        <button data-action="backup_run" data-out="cron-out">Run backup</button>
      </div>
      <pre id="cron-out" class="out" hidden></pre>
    </div>
    <div class="card">
      <h3>How the cron is started</h3>
      <p class="muted">Windows task (install-tasks.bat) or host cron, every minute:</p>
      <pre id="cli-cron" class="code"></pre>
      <p class="muted">Backup by hand (the scheduler runs it every 6 hours):</p>
      <pre id="cli-backup" class="code"></pre>
      <p class="muted">Or cron-job.org (GET), with MEALBOARD_CRON_KEY from .env:</p>
      <pre id="cron-url" class="code"></pre>
    </div>
  </div>

  <div class="card">
    <div class="bar">
      <h3>Automatic no-shows: dry run</h3>
      <form id="preview-form" class="inline">
        <label>Pretend it is <input type="datetime-local" name="at"></label>
        <button type="submit">Preview</button>
      </form>
    </div>
    <p class="muted">What the next cron run would do for yesterday and today. Nothing is written.</p>
    <div id="preview"></div>
  </div>

  <div class="card">
    <h3>Last cron runs</h3>
    <div id="cron-log"></div>
  </div>

  <div class="grid2">
    <div class="card">
      <h3>Meals already processed</h3>
      <p class="muted">Delete a line to let the cron process that meal again (bookings already marked stay marked).</p>
      <div id="marked"></div>
    </div>
    <div class="card">
      <h3>Daily order list</h3>
      <div id="daily"></div>
      <form class="stack" data-form="daily_list_send" data-out="daily-out">
        <label>Day<input type="date" name="day"></label>
        <label>Send to (empty = saved recipients)<input name="to" placeholder="a@company.com, b@company.com"></label>
        <button type="submit">Send the list now</button>
      </form>
      <pre id="daily-out" class="out" hidden></pre>
    </div>
  </div>
</section>

<!-- INJECT -->
<section data-panel="inject" hidden>
  <div class="bar">
    <h2>Inject test data</h2>
    <span id="injected" class="muted"></span>
  </div>
  <p class="alert warn">Everything here writes to the database <b>{{ $database }}</b>. Missed meals add real strikes and notifications. Use the "test people" option to stay away from real users, and remove the test data when you are done.</p>

  <div class="grid2">
    <form class="card stack" data-form="inject_users" data-out="inj-out">
      <h3>1. Test people</h3>
      <p class="muted">Usernames start with <code>test_</code>, password = the default password (MEALBOARD_DEFAULT_PASSWORD).</p>
      <label>How many<input type="number" name="count" value="5" min="1" max="200"></label>
      <label>Role
        <select name="role"><option>employee</option><option>intern</option><option>responsible</option><option>manager</option><option>admin</option><option>door</option></select>
      </label>
      <label>Department<input name="department" value="Test department"></label>
      <fieldset><legend>Meal plan</legend>
        <label class="check"><input type="checkbox" name="meals" value="breakfast" checked> Breakfast</label>
        <label class="check"><input type="checkbox" name="meals" value="lunch" checked> Lunch</label>
        <label class="check"><input type="checkbox" name="meals" value="dinner" checked> Dinner</label>
      </fieldset>
      <button class="primary" type="submit">Create people</button>
    </form>

    <form class="card stack" data-form="inject_bookings" data-out="inj-out">
      <h3>2. Bookings</h3>
      <p class="muted">Admin write: skips the booking deadlines. An existing booking of that day gets the meals added.</p>
      <div class="row"><label>From<input type="date" name="from" required></label><label>To<input type="date" name="to"></label></div>
      <label>Who
        <select name="scope" data-toggle-who><option value="test">Test people</option><option value="random">Random real employees</option><option value="user">One person</option></select>
      </label>
      <label data-who hidden>Id, e-mail or username<input name="who"></label>
      <label>How many people (max)<input type="number" name="count" value="10" min="1" max="500"></label>
      <fieldset><legend>Meals</legend>
        <label class="check"><input type="checkbox" name="meals" value="breakfast"> Breakfast</label>
        <label class="check"><input type="checkbox" name="meals" value="lunch" checked> Lunch</label>
        <label class="check"><input type="checkbox" name="meals" value="dinner"> Dinner</label>
      </fieldset>
      <label class="check"><input type="checkbox" name="weekends"> Include Saturday and Sunday</label>
      <button class="primary" type="submit">Create bookings</button>
    </form>

    <form class="card stack" data-form="inject_door" data-out="inj-out">
      <h3>3. Door: served / missed</h3>
      <p class="muted">Acts like the door PC on the open bookings of one meal. Missed meals add strikes (and notifications).</p>
      <div class="row"><label>Day<input type="date" name="day" required></label>
        <label>Meal<select name="meal"><option>breakfast</option><option selected>lunch</option><option>dinner</option></select></label></div>
      <div class="row"><label>% served<input type="number" name="served" value="80" min="0" max="100"></label>
        <label>% missed<input type="number" name="missed" value="20" min="0" max="100"></label></div>
      <label>Whose bookings
        <select name="scope"><option value="test">Test people only</option><option value="all">Everyone (real people too!)</option></select>
      </label>
      <button class="primary" type="submit">Mark</button>
    </form>

    <form class="card stack" data-form="inject_heartbeat" data-out="inj-out">
      <h3>4. Door heartbeat</h3>
      <p class="muted">Pretend a door PC checked in. A door with old unsent marks holds back the automatic no-shows.</p>
      <div class="row"><label>Door id<input name="door" value="test-door-1" required></label><label>Name<input name="name" value="Test door"></label></div>
      <div class="row"><label>Last seen (minutes ago)<input type="number" name="seenMinutesAgo" value="0" min="0"></label>
        <label>Close after (min)<input type="number" name="closeAfter" value="0" min="0" max="180"></label></div>
      <div class="row"><label>Unsent marks<input type="number" name="pending" value="0" min="0"></label>
        <label>Oldest unsent (minutes ago)<input type="number" name="oldestMinutes" value="0" min="0"></label></div>
      <button class="primary" type="submit">Save heartbeat</button>
    </form>
  </div>

  <form class="card stack" data-form="inject_row" data-out="inj-out" id="row-form">
    <h3>5. Any row (JSON)</h3>
    <div class="row">
      <label>Table<select name="table" id="row-table"></select></label>
      <span class="muted" id="row-columns"></span>
    </div>
    <label>Row<textarea name="data" rows="8" spellcheck="false" id="row-json">{}</textarea></label>
    <button class="primary" type="submit">Insert row</button>
  </form>

  <pre id="inj-out" class="out" hidden></pre>

  <div class="card danger">
    <h3>Remove test data</h3>
    <p class="muted">Deletes every <code>test_</code> person with their bookings, plus every row and door added from this page. Strikes given by injected bookings are taken back.</p>
    <button class="danger" data-action="cleanup" data-out="inj-out" data-confirm="Delete all test data?">Remove all test data</button>
  </div>
</section>

<!-- TABLES -->
<section data-panel="tables" class="tb" hidden>
  <aside class="tb-side">
    <label class="tb-filter"><i class="ri ri-search"></i><input id="tb-filter" placeholder="Search tables" autocomplete="off"></label>
    <div id="tb-list" class="tb-list"></div>
  </aside>
  <div class="tb-main">
    <header class="tb-head">
      <div class="crumbs"><span>Tables</span><i class="ri ri-right"></i><strong id="tb-title">-</strong></div>
      <button type="button" class="icon-btn" id="tb-refresh" title="Refresh"><i class="ri ri-refresh"></i></button>
      <span class="grow"></span>
      <button type="button" class="primary" id="tb-new"><i class="ri ri-add"></i> New record</button>
    </header>
    <form id="tb-search" class="searchbar" autocomplete="off">
      <i class="ri ri-search"></i>
      <input name="q" placeholder="Search in every column, then press Enter">
      <button type="button" class="icon-btn small" id="tb-clear" title="Clear" hidden><i class="ri ri-close"></i></button>
    </form>
    <div class="tb-scroll"><table id="tb-table" class="records"></table></div>
    <footer class="tb-foot">
      <span id="tb-total" class="hint"></span>
      <span class="grow"></span>
      <label class="hint">Per page
        <select id="tb-per"><option>15</option><option selected>30</option><option>50</option><option>100</option><option>200</option></select>
      </label>
      <button type="button" class="icon-btn" id="tb-prev" title="Previous page"><i class="ri ri-left"></i></button>
      <span id="tb-page" class="hint"></span>
      <button type="button" class="icon-btn" id="tb-next" title="Next page"><i class="ri ri-right"></i></button>
    </footer>
    <div id="tb-bulk" class="bulkbar" hidden>
      <span>Selected <strong id="tb-count">0</strong></span>
      <button type="button" class="link" id="tb-unselect">Reset</button>
      <span class="grow"></span>
      <button type="button" class="danger" id="tb-bulk-delete"><i class="ri ri-delete"></i> Delete selected</button>
    </div>
  </div>
</section>

<!-- EMAIL & DOORS -->
<section data-panel="email" hidden>
  <div class="grid2">
    <form class="card stack" data-form="smtp_test" data-out="mail-out">
      <h3>Send a test e-mail now</h3>
      <p class="muted">Goes straight to the SMTP server: shows the exact error if it fails.</p>
      <label>To<input name="to" type="email" required></label>
      <button class="primary" type="submit">Send now</button>
    </form>
    <form class="card stack" data-form="email_queue_test" data-out="mail-out">
      <h3>Queue a test e-mail</h3>
      <p class="muted">Goes into the queue and is sent by the cron (tests the queue path).</p>
      <label>To<input name="to" type="email" required></label>
      <button type="submit">Queue</button>
    </form>
  </div>
  <pre id="mail-out" class="out" hidden></pre>
  <div class="card">
    <div class="bar"><h3>E-mail queue (last 50)</h3><button data-run="email">Refresh</button></div>
    <div id="email-rows" class="scroll"></div>
  </div>
  <div class="card">
    <h3>Door PCs (last 3 days)</h3>
    <div id="doors"></div>
  </div>
</section>

<!-- ACTIVITY -->
<section data-panel="activity" hidden>
  <div class="bar"><h2>What was done from this panel</h2><button data-run="activity">Refresh</button></div>
  <div id="activity-rows" class="scroll"></div>
</section>

</main>

<!-- Record side panel (Tables) -->
<div id="rec" class="rec-overlay" hidden>
  <aside class="rec-panel" role="dialog" aria-modal="true" aria-labelledby="rec-title">
    <header class="rec-head">
      <h3 id="rec-title">Record</h3>
      <button type="button" class="icon-btn" data-rec-close title="Close (Esc)"><i class="ri ri-close"></i></button>
    </header>
    <form id="rec-form" class="rec-body" autocomplete="off"></form>
    <footer class="rec-foot">
      <button type="button" class="danger" id="rec-delete"><i class="ri ri-delete"></i> Delete</button>
      <span class="grow"></span>
      <button type="button" data-rec-close>Cancel</button>
      <button type="submit" class="primary" form="rec-form" id="rec-save">Save</button>
    </footer>
  </aside>
</div>
@endif
<script src="/control-assets/app.js?v={{ $version }}"></script>
</body>
</html>
