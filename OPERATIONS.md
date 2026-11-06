# Running Mealboard (Laravel)

## Scripts (Windows)

| File | What it does |
| --- | --- |
| `start.bat` | MySQL 8 (port 3307) + the scheduler window + the development server on `http://127.0.0.1:8080` |
| `install-tasks.bat` | A Windows task that runs `php artisan schedule:run` every minute (use it instead of the scheduler window) |
| `set-password.bat` | Sets a new password for one account |
| `deploy\optimize.bat` | Production speed-ups: class map, cached config/routes/views, debug off. **Run after every code or `.env` change** |
| `deploy\unoptimize.bat` | Back to development (needed before `php artisan serve` or the tests after an optimize) |
| `deploy\apache-start.bat` / `apache-stop.bat` | The production web server: its own Apache on `http://127.0.0.1:8088`, next to XAMPP's |

`php artisan serve` (and `start.bat`) is for development only: it answers one request at a time.
For real use, run the site with `deploy\apache-start.bat` (see "Production" below).

## Commands

```
php artisan mealboard:cron           automatic no-shows + daily order list + waiting e-mails (every 5 min)
php artisan mealboard:backup         database dump + photos zip + clean-up (every 6 h)   -> backups/
php artisan mealboard:send-emails    send the queued notification e-mails
php artisan mealboard:daily-orders   send the daily order list when it is time
php artisan mealboard:sessions-gc    remove old sessions, expired API tokens, old failed logins (daily, 03:30)
php artisan mealboard:set-password <e-mail or username> <password>
php artisan schedule:run             what the Windows task runs every minute
```

The **System** page (super admin) shows if all of this is running and has "Run now" buttons.

## Control panel (`/control`)

A separate page for whoever runs the server, not part of the app: `http://<site>/control` (same page and look as the
old `public_html/control/`). Sign in with the fixed login from `.env` (`MEALBOARD_CONTROL_USER` /
`MEALBOARD_CONTROL_PASSWORD`, switched off when empty) or with any active super admin account. It has its own sign-in
(signing out of it does not sign you out of the app), signs out after 2 hours idle, and locks after 5 wrong passwords.

| Tab | What it does |
| --- | --- |
| Health | Every check in one view: PHP and extensions, debug mode, folders, database and tables, the notifications index, cron, backups, e-mail and the daily list, door PCs, the API seen from the browser, and a **Run logic tests** button (`php artisan test` on `mealboard_test`, ~1 min, needs development mode) |
| Cron | Run the cron / send the queued e-mails / run a backup now; the no-show **dry run** ("pretend it is…", writes nothing); the last runs; meals already processed (delete one to let the cron do it again); send the daily order list now |
| Inject data | Test people (`test_…`), bookings for a date range, door served / missed marks, a door heartbeat, any row as JSON — and **Remove all test data** (strikes given by test data are taken back) |
| Tables | Browse, search, sort, add, edit and delete rows of every app table (system tables read only). Booking changes add or give back strikes like the door does |
| E-mail & doors | Send a test e-mail now (shows the exact SMTP error) or through the queue; the queue with Retry / Delete; the door PCs with Forget |
| Activity | Everything done from the panel: when, who, what, result |

**Change `MEALBOARD_CONTROL_PASSWORD` (or empty both lines) before the site goes online**: this page can change every table.

## Admin powers over who eats

- **Book people** (`/diners`): put anyone on any meal, whatever the rules say (past or locked days, paused people, meals
  outside their plan, Ramadan, switched-off accounts, people who never booked). One day or a range (up to 62 days, optionally
  only the days whose menu serves that meal), the ticked people or everyone in the filtered list. Take meals off again, and
  mark meals served or not served. No strike is ever added; clearing a "missed" mark gives its strike back.
- **Serve anyway** (Serve page): on any NOT ALLOWED result, the admin can let the person eat: the meal is booked if needed,
  a "missed" mark is cleared (strike given back), the meal is marked served and the gate opens.
- **Users**: add or take back strikes, lift a pause, switch accounts on or off, change meal access.

## Tests

```
tools\make-test-db.bat                 once (and when the real copy has new data): makes the test database mealboard_test
C:\xampp\php\php.exe artisan test      runs the whole suite (176 tests, ~45 s; also the "Run logic tests" button on /control)
C:\xampp\php\php.exe artisan test --filter=BookingsTest
```

The tests run on `mealboard_test`, a clone of the copy, and roll back every change; they refuse to run on any other database.
They cover the booking rules and deadlines, meal plans, strikes and pauses, the automatic no-shows, the admin powers
(Book people, Serve anyway, served marks), attendance, the door check (Serve), the whole JSON API with its permissions,
who may open which page, invoices and statistics figures, the System page, the settings rules, and that every compiled icon
prints exactly what the icon component prints. Run `deploy\unoptimize.bat` first if `deploy\optimize.bat` was used.

## `.env` switches

- `MEALBOARD_SEND_MAIL=false` — never send real e-mail from this copy (set to `true`, or remove, on the live server).
- `MEALBOARD_CRON_KEY=...` — lets `GET /api/cron-run?key=...` run the cron from cron-job.org or another host.
- `MEALBOARD_BACKUP_DIR` — where backups go (default `backups/` in the project, outside `public/`).
- `MEALBOARD_CORS_ORIGINS` — addresses allowed to call `/api` from another site (default `*`).
- `MEALBOARD_CONTROL_USER` / `MEALBOARD_CONTROL_PASSWORD` — fixed sign-in for `/control` (this copy: `Admin` / `Admin`, like the old panel). Empty = only super admins.
- `DB_PERSISTENT=true` (default) — reuse database connections between requests. Keep it on (see below).
- `DB_HOST=127.0.0.1` — never `localhost` on Windows: `localhost` tries IPv6 first and costs **2 seconds per connection**.

## The JSON API (`/api`)

For the desktop door app and the gate. Same addresses and answers as the PHP API.
Sign in with `POST /api/auth/login` and send `Authorization: <token>`.

`auth/login|refresh|logout` · `users`, `users/search`, `users/by-badge`, `users/{id}` (door may only set `card_id`) ·
`orders` (`day`, `from`, `to`, `since`, `meal`, `notMissed`, `user`, `page`, `perPage`, `fields`, `expand=user`),
`orders/first-missed`, `orders/{id}` (door may only set the served / no-show flags) ·
`mealboard/door-heartbeat` · `booking-rules`, `menu` (public) · `meal-groups`, `department-rules` (edit: super admin) ·
`gate` (admin: open / devices / test / choose) · `cron-run?key=` · `files/{table}/{id}/{name}`.

The web pages do not use the API; they read the database directly.

---

## Production

### On this Windows PC (XAMPP)

1. `deploy\optimize.bat`
2. `deploy\apache-start.bat` → the site is on `http://127.0.0.1:8088` (port in `deploy\apache-mealboard.conf`).
3. After any code or `.env` change: `deploy\optimize.bat`, then `deploy\apache-stop.bat` and `deploy\apache-start.bat`.
   The production PHP never re-reads changed files on its own (that is what makes it fast).
4. Once per database: `database\sql\2026-11-06-performance-indexes.sql` (index for notifications).

What `deploy\` sets up, and why each one matters (all measured, see the table below):

| Setting | Where | Effect |
| --- | --- | --- |
| Its own OPcache (`opcache.cache_id=mealboard`) | `deploy\php-overrides.ini` | **The big one.** On Windows every PHP of the same user shares one code cache. Without its own id, this Apache ran *old* code cached by XAMPP's Apache. ×2 on every page |
| Cached config/routes/views, class map, debug off | `deploy\optimize.bat` | about ×4 on every request |
| Reused database connections | `config/database.php` | a new MySQL connection costs 2–16 ms on Windows |
| One file name per compiled view | `app/Support/WindowsSafeBladeCompiler.php` | Laravel names compiled views differently with `\` and `/`, so on Windows the views compiled in advance were never used and were compiled again under load ("Access denied" errors) |
| No file-date checks on views | `VIEW_CHECK_CACHE_TIMESTAMPS=false` | one disk access less per view |
| Icons compiled into the views | `app/Support/InlineIcons.php`, `@icon(...)` | an icon component costs ~20 µs and pages draw hundreds; the HTML is byte-for-byte the same (checked on 28 pages) |
| `AcceptFilter none`, `EnableSendfile Off` | `deploy\apache-mealboard.conf` | Windows Apache otherwise leaves some connections hanging (requests stuck for the whole test) |
| JIT off | `deploy\php-overrides.ini` | XAMPP turns the JIT on without memory: it only logs errors |
| Session sweep 2 in 1000, plus daily `mealboard:sessions-gc` | `config/session.php` | the sweep reads every session file |

### On a Linux server (recommended for the live site)

- PHP 8.2+ **FPM** (not mod_php), OPcache on with `opcache.validate_timestamps=0`, `opcache.preload` is optional.
- `composer install --no-dev --optimize-autoloader --classmap-authoritative` (dropping the development packages saves ~10%).
- `APP_ENV=production APP_DEBUG=false VIEW_CHECK_CACHE_TIMESTAMPS=false php artisan optimize`
- Nginx: `root /path/to/mealboard-laravel/public; location / { try_files $uri /index.php?$query_string; }`, PHP via FPM.
- MySQL on the same machine: `DB_SOCKET=/var/run/mysqld/mysqld.sock` instead of a network port.
- Cron: `* * * * * cd /path/to/mealboard-laravel && php artisan schedule:run >> /dev/null 2>&1`

---

## Performance: what was measured

`tools\load-test.mjs` plays signed-in employees, admins and the door app at the same time (32 requests in flight).
The Mealboard Apache was **limited to one CPU core** (`tools\pin-one-core.ps1`); MySQL and the test program ran on other
cores. Machine: Intel Core Ultra 5 228V laptop, Windows 11, XAMPP PHP 8.2 (thread-safe build in Apache), MySQL 8.0.

```
node tools/load-test.mjs --url=http://127.0.0.1:8088 --duration=30s --concurrency=32 --door=<door account e-mail>
node tools/load-test.mjs --mix=api ...          only what the door app calls
node tools/load-test.mjs --path=/week ...       one page
```

Steps (full mix, one core): **9 → 36 → 72 → 81 requests per second**, 0 errors in the final runs.

| One core, final | Requests / second |
| --- | ---: |
| Empty Laravel request (`/up`), the floor of any page | ~185 |
| Door app / API only (day bookings, rules, heartbeats, served marks, badges) | **~160** |
| Booking rules (API) | ~176 |
| Login page | ~125 |
| Notifications page | ~99 |
| Profile | ~88 |
| Serve page, Attendance | ~85 |
| Dashboard | ~75 |
| Week page (the page employees use most) | ~63 |
| Users (108 people in one table) | ~23 |
| Statistics (a whole month analysed) | ~13 |
| **Realistic mix** (employees, admins and the door together) | **~81** |

For scale: 107 staff opening the app in the same minute before the booking deadline is about 18–20 requests per second.
One core handles 4× that peak with the mix above, and the door app's ~1 request per badge scan is far below its limit.

### 200 requests per second on one core: what it takes

**Not reachable on this setup as it is.** Even an *empty* Laravel request costs about 5.5 ms on this Windows/XAMPP PHP
(~185 per second), so no page can go faster than that, and real pages do real work. Two ways to go past it:

1. **Linux + PHP-FPM** (the recommended live setup above). Laravel's fixed cost per request is usually 2–3× lower than on
   Windows' thread-safe PHP in Apache. Expected (not measured here): the API at 350+ and the realistic mix around 150–250 per
   second per core.
2. **Laravel Octane** (RoadRunner, Swoole or FrankenPHP): Laravel stays loaded in memory between requests, which removes most
   of the fixed cost. Typical result is 3–5× more requests per core. It needs extra packages (`laravel/octane` and a server
   binary) and care with data kept in static variables between requests: `BookingRules::current()` and
   `ManagerMeals::load()` would have to be reset at the start of every request (the icon cache can stay).

Both need software installed that is not on this PC yet; they were not done without your go-ahead.
