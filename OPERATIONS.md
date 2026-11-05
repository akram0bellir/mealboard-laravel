# Running Mealboard (Laravel)

## Scripts (Windows)

| File | What it does |
| --- | --- |
| `start.bat` | MySQL 8 (port 3307) + the scheduler window + the website on `http://127.0.0.1:8080` |
| `install-tasks.bat` | A Windows task that runs `php artisan schedule:run` every minute (use it instead of the scheduler window) |
| `set-password.bat` | Sets a new password for one account |

## Commands

```
php artisan mealboard:cron           automatic no-shows + daily order list + waiting e-mails (every 5 min)
php artisan mealboard:backup         database dump + photos zip + clean-up (every 6 h)   -> backups/
php artisan mealboard:send-emails    send the queued notification e-mails
php artisan mealboard:daily-orders   send the daily order list when it is time
php artisan mealboard:set-password <e-mail or username> <password>
php artisan schedule:run             what the Windows task runs every minute
```

The **System** page (super admin) shows if all of this is running and has "Run now" buttons.

## Tests

```
tools\make-test-db.bat                 once (and when the real copy has new data): makes the test database mealboard_test
C:\xampp\php\php.exe artisan test      runs the whole suite (about 150 tests, ~40 s)
C:\xampp\php\php.exe artisan test --filter=BookingsTest
```

The tests run on `mealboard_test`, a clone of the copy, and roll back every change; they refuse to run on any other database.
They cover the booking rules and deadlines, meal plans, strikes and pauses, the automatic no-shows, the admin "Book people"
page, attendance, the door check (Serve), the whole JSON API with its permissions, who may open which page, invoices and
statistics figures, the System page and the settings rules.

## `.env` switches

- `MEALBOARD_SEND_MAIL=false` — never send real e-mail from this copy (set to `true`, or remove, on the live server).
- `MEALBOARD_CRON_KEY=...` — lets `GET /api/cron-run?key=...` run the cron from cron-job.org or another host.
- `MEALBOARD_BACKUP_DIR` — where backups go (default `backups/` in the project, outside `public/`).
- `MEALBOARD_CORS_ORIGINS` — addresses allowed to call `/api` from another site (default `*`).

## The JSON API (`/api`)

For the desktop door app and the gate. Same addresses and answers as the PHP API.
Sign in with `POST /api/auth/login` and send `Authorization: <token>`.

`auth/login|refresh|logout` · `users`, `users/search`, `users/by-badge`, `users/{id}` (door may only set `card_id`) ·
`orders` (`day`, `from`, `to`, `since`, `meal`, `notMissed`, `user`, `page`, `perPage`, `fields`, `expand=user`),
`orders/first-missed`, `orders/{id}` (door may only set the served / no-show flags) ·
`mealboard/door-heartbeat` · `booking-rules`, `menu` (public) · `meal-groups`, `department-rules` (edit: super admin) ·
`gate` (admin: open / devices / test / choose) · `cron-run?key=` · `files/{table}/{id}/{name}`.

The web pages do not use the API; they read the database directly.
