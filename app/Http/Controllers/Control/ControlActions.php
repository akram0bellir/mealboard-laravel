<?php

namespace App\Http\Controllers\Control;

use App\Services\Backup;
use App\Services\BookingRules;
use App\Services\Cron;
use App\Services\DayOrders;
use App\Services\Mailer;
use App\Services\MealTimes;
use App\Services\Settings;
use App\Services\Standing;
use App\Support\ControlRows;
use App\Support\Days;
use App\Support\Ids;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the control panel (/control) can do: health checks, cron tests, test data, the table editor, e-mail tests.
 * Port of the PHP API's control/actions.php, on the Laravel services and database.
 */
class ControlActions
{
    /** Usernames of injected test people start with this. */
    public const TEST_PREFIX = 'test_';

    /** Actions that only read: not written to the activity log. */
    public const READ_ACTIONS = ['health', 'cron_state', 'cron_preview', 'emails_list', 'injected', 'tables', 'table_rows', 'table_columns', 'activity'];

    /** Internal tables the panel shows (read only) and how they are sorted. */
    private const INTERNAL_TABLES = [
        'pending_emails' => '`created` DESC',
        '_mealboard_doors' => '`last_seen` DESC',
        '_mealboard_marked' => '`day` DESC, `meal`',
        '_mealboard_daily_sent' => '`tried_at` DESC',
        '_mealboard_settings' => '`key`',
        'login_attempts' => '`first_at` DESC',
    ];

    /** Key column of the internal tables ('day|meal' = two columns joined). */
    private const INTERNAL_KEYS = [
        'pending_emails' => 'id',
        '_mealboard_doors' => 'door',
        '_mealboard_marked' => 'day|meal',
        '_mealboard_daily_sent' => 'day',
        '_mealboard_settings' => 'key',
        'login_attempts' => 'attempt_key',
    ];

    /** Internal tables whose rows may be deleted from the panel. */
    private const INTERNAL_DELETABLE = ['pending_emails', '_mealboard_doors', '_mealboard_marked', 'login_attempts'];

    /** Columns that point to a person: shown with the person's name. */
    private const PERSON_COLUMNS = ['user', 'sent_by', 'switched_by'];

    private const BOOKED = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    public function __construct(private string $by = '')
    {
    }

    public function dispatch(string $action, array $b): array
    {
        return match ($action) {
            'health' => ['checks' => $this->health()],

            'cron_state' => $this->cronState(),
            'cron_preview' => $this->cronPreview(isset($b['at']) ? (string) $b['at'] : null),
            'cron_run' => Cron::run('control'),
            'emails_flush' => $this->emailsFlush(),
            'daily_list_send' => $this->dailyListSend($b),
            'backup_run' => $this->backupRun(),
            'logic_tests' => $this->logicTests(),

            'smtp_test' => $this->smtpTest($b),
            'email_queue_test' => $this->emailQueueTest($b),
            'emails_list' => ['rows' => DB::table('pending_emails')->select('id', 'to_address', 'subject', 'created', 'attempts', 'sent_at', 'error')->orderByDesc('created')->limit(50)->get()->all()],
            'email_retry' => $this->emailRetry($b),

            'inject_users' => $this->injectUsers($b),
            'inject_bookings' => $this->injectBookings($b),
            'inject_door' => $this->injectDoor($b),
            'inject_heartbeat' => $this->injectHeartbeat($b),
            'inject_row' => $this->injectRow($b),
            'injected' => $this->injectedSummary(),
            'cleanup' => $this->cleanup(),

            'tables' => ['tables' => $this->tableList()],
            'table_rows' => $this->tableRows($b),
            'table_delete' => $this->tableDelete($b),
            'table_save' => $this->tableSave($b),
            'table_columns' => ['columns' => $this->tableColumns($this->knownTable((string) ($b['table'] ?? '')))],

            'activity' => ['rows' => array_map(fn ($e) => $e + ['atText' => self::msText((int) $e['at'])], Settings::get('control_log'))],

            default => throw new ControlError(404, 'Unknown action.'),
        };
    }

    /** Remember what the panel did (last 100 actions). Never breaks the action. */
    public function log(string $action, bool $ok, string $summary): void
    {
        try {
            $log = Settings::get('control_log');
            array_unshift($log, ['at' => Cron::nowMs(), 'action' => $action, 'ok' => $ok, 'summary' => mb_substr($summary, 0, 500), 'by' => $this->by]);
            Settings::put('control_log', array_slice($log, 0, 100));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Small helpers                                                       */
    /* ------------------------------------------------------------------ */

    private static function fail(int $status, string $message): never
    {
        throw new ControlError($status, $message);
    }

    /** Remember the ids of injected rows, so "Remove test data" can find them. */
    private function track(string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $all = Settings::get('control_injected');
        $all[$table] = array_values(array_unique(array_merge($all[$table] ?? [], array_map('strval', $ids))));
        Settings::put('control_injected', $all);
    }

    public static function msText(?int $ms): string
    {
        return $ms === null || $ms <= 0 ? '' : CarbonImmutable::createFromTimestampMs($ms, config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private static function ageText(int $seconds): string
    {
        return match (true) {
            $seconds < 90 => $seconds . ' s ago',
            $seconds < 5400 => round($seconds / 60) . ' min ago',
            $seconds < 172800 => round($seconds / 3600, 1) . ' h ago',
            default => round($seconds / 86400, 1) . ' days ago',
        };
    }

    /** Path of a PHP command-line program, or null (under Apache PHP_BINARY is httpd.exe). */
    private static function phpCli(): ?string
    {
        $candidates = [];
        if (PHP_BINARY !== '' && preg_match('/^php(-cli)?(\.exe)?$/i', basename(PHP_BINARY))) {
            $candidates[] = PHP_BINARY;
        }
        $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        array_push($candidates, 'C:\\xampp\\php\\php.exe', '/usr/bin/php', '/usr/local/bin/php');
        foreach ($candidates as $path) {
            if (@is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private static function execAvailable(): bool
    {
        return function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    }

    private static function meal(mixed $value): ?string
    {
        return MealTimes::mealOf((string) $value);
    }

    private function dailySettings(): array
    {
        return array_merge(['recipients' => [], 'auto' => false, 'hour' => 14, 'day' => 'tomorrow'], Settings::get('daily_orders'));
    }

    private static function recipients(mixed $raw): array
    {
        try {
            return DayOrders::addresses(is_array($raw) ? implode(',', $raw) : (string) $raw);
        } catch (\RuntimeException $e) {
            self::fail(400, $e->getMessage());
        }
    }

    private static function dayKey(mixed $value): string
    {
        return Days::keyOf(ControlRows::dateIn($value));
    }

    /* ------------------------------------------------------------------ */
    /* Health: what works and what doesn't                                 */
    /* ------------------------------------------------------------------ */

    private function health(): array
    {
        $checks = [];
        $add = function (string $group, string $name, string $status, string $detail, string $fix = '') use (&$checks): void {
            $checks[] = compact('group', 'name', 'status', 'detail', 'fix');
        };

        /* Server */
        $add('Server', 'PHP version', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'fail', PHP_VERSION, 'Laravel 12 needs PHP 8.2 or newer.');
        $extensions = [
            'pdo_mysql' => ['fail', 'Needed for the database.'],
            'mbstring' => ['fail', 'Needed for text handling.'],
            'json' => ['fail', 'Needed for the API.'],
            'openssl' => ['warn', 'Needed for SMTP over SSL/TLS.'],
            'gd' => ['warn', 'Without it photos are served without thumbnails.'],
            'zip' => ['warn', 'Without it the photo backup is skipped.'],
            'Zend OPcache' => ['warn', 'Without it every page is much slower.'],
        ];
        foreach ($extensions as $ext => [$level, $why]) {
            $on = extension_loaded($ext);
            $add('Server', "Extension $ext", $on ? 'ok' : $level, $on ? 'loaded' : 'missing', $on ? '' : $why . " Turn on extension=$ext in php.ini.");
        }
        $add('Server', 'Time zone', 'info', config('app.timezone') . ' - server time ' . Days::now()->format('Y-m-d H:i:s'));
        $cronKey = (string) config('mealboard.cron_key');
        $add('Server', 'Cron key', $cronKey === '' ? 'warn' : 'ok', $cronKey === '' ? 'empty: /api/cron-run is disabled' : 'set', 'Set a long random MEALBOARD_CRON_KEY in .env (only needed for cron-job.org).');
        $add('Server', 'Debug mode', config('app.debug') ? 'warn' : 'ok', config('app.debug') ? 'on (APP_DEBUG=true)' : 'off', 'Run deploy\optimize.bat before going live: debug on shows error details to everyone and is slow.');
        $add('Server', 'Cached config', app()->configurationIsCached() ? 'ok' : 'info', app()->configurationIsCached() ? 'cached (production mode)' : 'not cached (development mode)');
        $php = self::phpCli();
        $add('Server', 'Run scripts from the panel', self::execAvailable() && $php !== null ? 'ok' : 'warn', self::execAvailable() ? ($php ?? 'no PHP command-line program found') : 'proc_open is disabled', 'Needed only for the "Run logic tests" button.');

        /* Folders */
        foreach (['upload_dir' => 'Uploads folder', 'backup_dir' => 'Backups folder'] as $key => $label) {
            $dir = (string) config("mealboard.$key");
            if ($dir === '' || !is_dir($dir)) {
                $add('Files', $label, 'warn', ($dir ?: '(not set)') . ' does not exist yet', 'It is created on first use; make sure PHP may write there.');
            } else {
                $add('Files', $label, is_writable($dir) ? 'ok' : 'fail', $dir . (is_writable($dir) ? ' (writable)' : ' (NOT writable)'));
            }
        }
        $logs = storage_path('logs');
        $add('Files', 'Laravel storage', is_writable($logs) && is_writable(storage_path('framework/sessions')) ? 'ok' : 'fail', is_writable($logs) ? storage_path() . ' (writable)' : storage_path() . ' (NOT writable)', 'PHP must be able to write in storage\ (sessions, logs, compiled views).');

        /* Database */
        try {
            $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;
        } catch (\Throwable $e) {
            $add('Database', 'Connection', 'fail', $e->getMessage(), 'Check DB_* in .env and that MySQL is running.');
            return $checks;
        }
        $add('Database', 'Connection', 'ok', 'MySQL ' . $version . ' / database ' . DB::connection()->getDatabaseName());
        if (stripos($version, 'mariadb') !== false) {
            $add('Database', 'Server type', 'fail', 'MariaDB found', 'Mealboard needs MySQL 8.0 (the notifications index uses a MySQL-only feature).');
        }

        $have = array_map(fn ($r) => strtolower((string) array_values((array) $r)[0]), DB::select('SHOW TABLES'));
        $missing = array_values(array_filter(
            array_merge(array_keys(ControlRows::TABLES), ['auth_tokens', 'sessions'], array_keys(self::INTERNAL_TABLES)),
            fn ($t) => !in_array(strtolower($t), $have, true) && !($t === 'sessions' && config('session.driver') !== 'database')
        ));
        $add('Database', 'Tables', $missing === [] ? 'ok' : 'fail', $missing === [] ? count($have) . ' tables, none missing' : 'missing: ' . implode(', ', $missing), 'Load the schema of the old database (README).');
        if ($missing !== []) {
            return $checks;
        }
        $hasIndex = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'notifications' AND index_name = 'ix_notifications_recipients'")->n > 0;
        $add('Database', 'Notifications index', $hasIndex ? 'ok' : 'warn', $hasIndex ? 'present' : 'missing', 'Run database\sql\2026-11-06-performance-indexes.sql once: the top bar is slow without it.');

        $rulesRows = DB::table('booking_rules')->count();
        $add('Database', 'Booking rules row', $rulesRows > 0 ? 'ok' : 'warn', $rulesRows . ' row(s)', 'Save the booking rules once in Settings.');
        $users = DB::table('user')->where('Status', 1)->count();
        $supers = DB::table('user')->where('Status', 1)->whereIn('User_Type', ['super_admin', 'super_admain', 'superadmin', 'super admin'])->count();
        $add('Database', 'People', $users > 0 ? 'ok' : 'warn', "$users active, $supers super admin(s)");
        $today = Days::today();
        $bookings = DB::table('oder')->where('date', '>=', "$today 00:00:00.000")->where('date', '<=', "$today 23:59:59.999")->count();
        $add('Database', 'Bookings today', 'info', $bookings . ' booking row(s) for ' . $today);
        $tests = DB::table('user')->where('username', 'like', self::TEST_PREFIX . '%')->count();
        if ($tests > 0) {
            $add('Database', 'Test data present', 'warn', "$tests test people (username " . self::TEST_PREFIX . '...)', 'Remove them on the Inject tab before going live.');
        }

        /* Cron */
        $log = Cron::log();
        $last = $log[0] ?? null;
        if ($last === null) {
            $add('Cron', 'Last run', 'fail', 'never recorded', 'Run install-tasks.bat (a Windows task runs "php artisan schedule:run" every minute), or start.bat for the scheduler window.');
        } else {
            $age = time() - intdiv((int) $last['at'], 1000);
            $add('Cron', 'Last run', $age <= 600 ? 'ok' : ($age <= 3600 ? 'warn' : 'fail'), self::ageText($age) . ' (' . $last['source'] . ', ' . $last['ms'] . ' ms)', 'The scheduler runs the cron every 5 minutes. Runs started from this panel or the System page count too.');
            $lastAuto = null;
            foreach ($log as $entry) {
                if (!in_array($entry['source'] ?? '', ['control', 'system'], true)) {
                    $lastAuto = $entry;
                    break;
                }
            }
            if ($lastAuto === null) {
                $add('Cron', 'Scheduled cron', 'fail', 'only manual runs so far', 'The scheduler (Windows task or cron-job.org) has never run: run install-tasks.bat.');
            } elseif ($lastAuto !== $last) {
                $autoAge = time() - intdiv((int) $lastAuto['at'], 1000);
                $add('Cron', 'Scheduled cron', $autoAge <= 600 ? 'ok' : 'warn', 'last scheduled run ' . self::ageText($autoAge));
            }
            $failedRuns = array_values(array_filter(array_slice($log, 0, 10), fn ($e) => empty($e['ok'])));
            $add('Cron', 'Errors (last 10 runs)', $failedRuns === [] ? 'ok' : 'fail', $failedRuns === [] ? 'none' : count($failedRuns) . ' run(s) failed: ' . implode(' | ', $failedRuns[0]['result']['errors'] ?? []));
        }
        $auto = BookingRules::current()->autoNoShowFrom;
        $add('Cron', 'Automatic no-shows', $auto === '' ? 'info' : 'ok', $auto === '' ? 'off (auto_no_show_from is empty)' : 'on since ' . $auto);

        /* Backups */
        $backupDir = (string) config('mealboard.backup_dir');
        $files = glob($backupDir . '/mealboard-*.sql.gz') ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        if ($files === []) {
            $add('Backups', 'Last database backup', 'fail', 'none found', 'The scheduler runs "php artisan mealboard:backup" every 6 hours: install the Windows task (install-tasks.bat).');
        } else {
            $age = time() - filemtime($files[0]);
            $add('Backups', 'Last database backup', $age <= 7 * 3600 ? 'ok' : ($age <= 26 * 3600 ? 'warn' : 'fail'), basename($files[0]) . ', ' . self::ageText($age) . ', ' . round(filesize($files[0]) / 1024) . ' KB', 'Backups should run every 6 hours.');
        }
        $lines = Backup::logTail(5);
        if ($lines !== []) {
            $bad = array_filter($lines, fn ($l) => str_contains($l, 'FAILED') || str_contains($l, 'SKIPPED'));
            $add('Backups', 'Backup log', $bad === [] ? 'ok' : 'warn', $bad === [] ? 'last entries OK' : end($bad));
        }

        /* E-mail */
        $smtp = DB::table('smtp')->first();
        $smtpText = trim((string) ($smtp->SMTP_server_host ?? '')) === '' ? 'not set' : $smtp->SMTP_server_host . ':' . $smtp->Port . ' as ' . (trim((string) $smtp->Sender_address) ?: '(no sender address)');
        if (!config('mealboard.send_mail')) {
            $add('E-mail', 'SMTP settings', 'warn', $smtpText . ' - switched off in .env (MEALBOARD_SEND_MAIL=false)', 'Remove MEALBOARD_SEND_MAIL=false from .env on the live server.');
        } else {
            $add('E-mail', 'SMTP settings', Mailer::isOn() ? 'ok' : 'warn', $smtpText, 'Fill in Settings -> SMTP in the app. Without it no e-mail goes out.');
        }
        $waiting = DB::table('pending_emails')->whereNull('sent_at')->count();
        $failing = DB::table('pending_emails')->whereNull('sent_at')->where('attempts', '>', 0)->count();
        $lastError = (string) (DB::table('pending_emails')->whereNull('sent_at')->where('error', '<>', '')->orderByDesc('created')->value('error') ?? '');
        $add('E-mail', 'Queue', $failing > 0 ? 'fail' : ($waiting > 20 ? 'warn' : 'ok'), "$waiting waiting, $failing failing" . ($lastError !== '' ? " - last error: $lastError" : ''));
        $daily = $this->dailySettings();
        if ($daily['auto']) {
            $target = $daily['day'] === 'today' ? $today : Days::add($today, 1);
            $lastSent = DB::table('_mealboard_daily_sent')->whereNotNull('sent_at')->orderByDesc('day')->first();
            $sentText = $lastSent === null ? 'never sent yet' : 'last sent for ' . $lastSent->day;
            $when = sprintf('every day at %d:00 (list of %s)', $daily['hour'], $daily['day']);
            if ((int) Days::now()->format('G') < (int) $daily['hour']) {
                $add('E-mail', 'Automatic daily list', 'ok', "$when - next one today at {$daily['hour']}:00, $sentText");
            } else {
                $row = DB::table('_mealboard_daily_sent')->where('day', $target)->first();
                if ($row !== null && $row->sent_at !== null) {
                    $add('E-mail', 'Automatic daily list', 'ok', "$when - today's list sent at " . self::msText((int) $row->sent_at));
                } elseif ($row !== null && $row->error !== '') {
                    $add('E-mail', 'Automatic daily list', 'fail', "$when - today's list failed: " . $row->error, 'Check SMTP with "Send a test e-mail now" on the E-mail tab; the cron retries every 10 minutes.');
                } else {
                    $add('E-mail', 'Automatic daily list', 'fail', "$when - today's list was not sent yet ($sentText)", 'The cron must be running (and e-mail switched on) for the list to go out.');
                }
            }
        } else {
            $add('E-mail', 'Automatic daily list', 'info', 'off');
        }

        /* Doors */
        $doors = Cron::activeDoors();
        if ($doors === []) {
            $add('Doors', 'Door PCs', 'info', 'no door seen in the last 3 days');
        }
        foreach ($doors as $door) {
            $age = time() - intdiv((int) $door->last_seen, 1000);
            $status = $age <= 300 ? 'ok' : 'warn';
            if ((int) $door->pending > 0 && $door->oldest_pending !== null && Cron::nowMs() - (int) $door->oldest_pending > 3600 * 1000) {
                $status = 'fail';
            }
            $add('Doors', 'Door ' . ($door->name ?: $door->door), $status, 'seen ' . self::ageText($age) . ', ' . (int) $door->pending . ' served mark(s) waiting to upload', 'A door that keeps unsent marks blocks the automatic no-shows.');
        }

        return $checks;
    }

    /* ------------------------------------------------------------------ */
    /* Cron                                                                */
    /* ------------------------------------------------------------------ */

    /** What the automatic no-show step would do now (or at $at), without writing. Same tests as Cron::autoNoShows(). */
    private function cronPreview(?string $at): array
    {
        $simulated = $at !== null && trim($at) !== '';
        $now = Days::now();
        if ($simulated) {
            try {
                $now = CarbonImmutable::parse(str_replace('T', ' ', $at), config('app.timezone'));
            } catch (\Throwable) {
                self::fail(400, 'Not a valid date and time.');
            }
        }

        $rules = BookingRules::current();
        $config = null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+([a-zA-Z]+)$/', $rules->autoNoShowFrom, $m) && self::meal($m[2]) !== null) {
            $config = ['day' => $m[1], 'meal' => self::meal($m[2])];
        }
        $rank = array_flip(Cron::MEALS);
        $today = $now->format('Y-m-d');
        $doors = Cron::activeDoors();
        $items = [];

        foreach ([Days::add($today, -1), $today] as $day) {
            foreach (Cron::MEALS as $meal) {
                $column = self::BOOKED[$meal];
                $counts = DB::table('oder')->where('date', '>=', "$day 00:00:00.000")->where('date', '<=', "$day 23:59:59.999")->where($column, 1)
                    ->selectRaw("COUNT(*) AS booked, COALESCE(SUM(`attended_$meal` = 1), 0) AS served, COALESCE(SUM(`no_show_$meal` = 1), 0) AS missed, COALESCE(SUM(`attended_$meal` = 0 AND `no_show_$meal` = 0), 0) AS open_count")
                    ->first();
                $end = Cron::mealEnd($day, $meal, $rules);
                $endTs = $end->getTimestamp();
                $marked = DB::table('_mealboard_marked')->where('day', $day)->where('meal', $meal)->first();

                $blocking = [];
                foreach ($doors as $door) {
                    if ((int) $door->last_seen < $endTs * 1000) {
                        $blocking[] = ($door->name ?: $door->door) . ' not seen since the meal end';
                    } elseif ($door->oldest_pending !== null && (int) $door->oldest_pending < $endTs * 1000) {
                        $blocking[] = ($door->name ?: $door->door) . ' still has ' . (int) $door->pending . ' unsent mark(s)';
                    }
                }

                [$state, $why] = match (true) {
                    $config === null => ['off', 'Automatic no-shows are off'],
                    $day < $config['day'] || ($day === $config['day'] && $rank[$meal] < $rank[$config['meal']]) => ['skip', 'Before the start (' . $config['day'] . ' ' . $config['meal'] . ')'],
                    $rules->ramadan && $meal === 'lunch' => ['skip', 'Ramadan: no lunch'],
                    $marked !== null => ['done', 'Already done at ' . self::msText((int) $marked->at) . ': ' . (int) $marked->marked . ' marked'],
                    $now->getTimestamp() < $endTs => ['wait', 'Meal not over: runs after ' . $end->format('Y-m-d H:i')],
                    $blocking !== [] => ['blocked', 'Waiting for doors: ' . implode('; ', $blocking)],
                    default => ['ready', 'Next cron run marks ' . (int) $counts->open_count . ' no-show(s)'],
                };

                $items[] = [
                    'day' => $day, 'meal' => $meal,
                    'booked' => (int) $counts->booked, 'served' => (int) $counts->served, 'missed' => (int) $counts->missed, 'open' => (int) $counts->open_count,
                    'endsAt' => $end->format('H:i'), 'state' => $state, 'why' => $why,
                ];
            }
        }

        return [
            'now' => $now->format('Y-m-d H:i'),
            'simulated' => $simulated,
            'autoNoShowFrom' => $rules->autoNoShowFrom,
            'ramadan' => $rules->ramadan,
            'items' => $items,
            'doors' => array_map(fn ($d) => [
                'door' => $d->door,
                'name' => $d->name,
                'lastSeen' => self::msText((int) $d->last_seen),
                'pending' => (int) $d->pending,
                'oldestPending' => self::msText($d->oldest_pending === null ? null : (int) $d->oldest_pending),
                'closeAfter' => (int) $d->close_after,
            ], $doors),
        ];
    }

    private function cronState(): array
    {
        $php = self::phpCli() ?? 'php';
        $artisan = base_path('artisan');
        return [
            'log' => array_map(fn ($e) => $e + ['atText' => self::msText((int) $e['at'])], Cron::log()),
            'marked' => DB::table('_mealboard_marked')->orderByDesc('day')->orderBy('meal')->limit(30)->get()->all(),
            'daily' => [
                'settings' => $this->dailySettings(),
                'last' => DB::table('_mealboard_daily_sent')->orderByDesc('tried_at')->limit(10)->get()->map(fn ($r) => [
                    'day' => $r->day,
                    'sent_at' => self::msText($r->sent_at === null ? null : (int) $r->sent_at),
                    'tried_at' => self::msText((int) $r->tried_at),
                    'error' => $r->error,
                ])->all(),
            ],
            'cronUrl' => '/api/cron-run?key=<MEALBOARD_CRON_KEY>',
            'cliCron' => "\"$php\" \"$artisan\" schedule:run     (every minute: runs mealboard:cron every 5 min, the backup every 6 h)\n\"$php\" \"$artisan\" mealboard:cron   (the cron alone)",
            'cliBackup' => "\"$php\" \"$artisan\" mealboard:backup",
        ];
    }

    private function emailsFlush(): array
    {
        [$sent, $failed] = Mailer::flushQueue(50);
        return ['sent' => $sent, 'failed' => $failed, 'smtpConfigured' => Mailer::isOn()];
    }

    private function dailyListSend(array $b): array
    {
        $day = self::dayKey($b['day'] ?? null) ?: Days::add(Days::today(), 1);
        $to = trim((string) ($b['to'] ?? '')) !== '' ? self::recipients($b['to']) : $this->dailySettings()['recipients'];
        if ($to === []) {
            self::fail(400, 'Give an address (no daily-list recipients are saved).');
        }
        try {
            return DayOrders::send($day, $to);
        } catch (\RuntimeException $e) {
            self::fail(400, 'E-mail failed: ' . $e->getMessage());
        }
    }

    private function backupRun(): array
    {
        $started = microtime(true);
        try {
            $r = Backup::run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'seconds' => round(microtime(true) - $started, 2), 'output' => 'Backup failed: ' . $e->getMessage()];
        }
        return [
            'ok' => $r['warnings'] === [],
            'seconds' => round(microtime(true) - $started, 2),
            'file' => $r['file'],
            'size' => $r['size'],
            'uploads' => $r['uploads'],
            'output' => implode("\n", array_merge(Backup::logTail(3), $r['warnings'])),
        ];
    }

    /** Runs the automated tests ("php artisan test") on the test database. Changes nothing in the real data. */
    private function logicTests(): array
    {
        if (!self::execAvailable()) {
            self::fail(400, 'This server does not allow running commands (proc_open is disabled). Run "php artisan test" by hand.');
        }
        if (app()->configurationIsCached()) {
            self::fail(400, 'The config is cached (deploy\optimize.bat): the tests cannot run like that. Run deploy\unoptimize.bat first, or "php artisan test" by hand.');
        }
        if (!is_dir(base_path('vendor/phpunit'))) {
            self::fail(400, 'The test tools are not installed on this server (composer install --no-dev).');
        }
        $php = self::phpCli() ?? self::fail(400, 'No PHP command-line program was found on this server.');

        // Only the system variables: the web process holds DB_DATABASE etc. from .env, which would point the
        // tests at the real database (phpunit.xml does not override variables that are already set).
        $env = [];
        foreach (['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'windir', 'TEMP', 'TMP', 'COMSPEC', 'PATHEXT', 'USERPROFILE', 'HOME', 'APPDATA', 'LOCALAPPDATA'] as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $env[$k] = $v;
            }
        }

        $started = microtime(true);
        $proc = proc_open([$php, base_path('artisan'), 'test', '--colors=never'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        if (!is_resource($proc)) {
            self::fail(500, 'Could not start PHP.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $code = -1;
        while (true) {
            $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                $code = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) - $started > 300) {
                proc_terminate($proc);
                $out .= "\n[stopped after 300 s]";
                break;
            }
            usleep(100000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $out = trim(preg_replace('/\e\[[\d;]*m/', '', $out));
        $passed = preg_match('/(\d+) passed/', $out, $p) ? (int) $p[1] : 0;
        $failed = preg_match('/(\d+) failed/', $out, $f) ? (int) $f[1] : 0;
        return [
            'ok' => $code === 0,
            'exitCode' => $code,
            'seconds' => round(microtime(true) - $started, 2),
            // The page reads "N passed, M failed" from the output.
            'output' => "$passed passed, $failed failed\n\n" . ($code === 0 ? mb_substr($out, -600) : mb_substr($out, -8000)),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* E-mail                                                              */
    /* ------------------------------------------------------------------ */

    private function smtpTest(array $b): array
    {
        $to = self::recipients($b['to'] ?? '');
        try {
            Mailer::send($to, 'Mealboard test e-mail', 'This is a test e-mail sent from the Mealboard control panel at ' . Days::now()->format('Y-m-d H:i:s') . ".\n\nIf you can read it, SMTP works.\n");
        } catch (\RuntimeException $e) {
            self::fail(400, 'E-mail failed: ' . $e->getMessage());
        }
        return ['sent' => true, 'to' => $to];
    }

    private function emailQueueTest(array $b): array
    {
        $ids = [];
        foreach (self::recipients($b['to'] ?? '') as $address) {
            $ids[] = $id = Ids::new();
            DB::table('pending_emails')->insert([
                'id' => $id, 'to_address' => $address, 'subject' => 'Mealboard queued test e-mail',
                'body' => 'Queued from the control panel at ' . Days::now()->format('Y-m-d H:i:s') . " and sent by the cron / queue.\n",
                'created' => Ids::now(), 'attempts' => 0, 'sent_at' => null, 'error' => '',
            ]);
        }
        $this->track('_emails', $ids);
        return ['queued' => count($ids)];
    }

    private function emailRetry(array $b): array
    {
        DB::table('pending_emails')->where('id', (string) ($b['id'] ?? ''))->update(['attempts' => 0, 'error' => '']);
        return ['ok' => true];
    }

    /* ------------------------------------------------------------------ */
    /* Injecting test data                                                 */
    /* ------------------------------------------------------------------ */

    /** Test people, random active employees, or one person. */
    private function pickUsers(string $scope, int $count, string $who = ''): array
    {
        $count = max(1, min(500, $count));
        if ($scope === 'user') {
            $who = trim($who);
            $row = DB::table('user')->where('id', $who)->orWhereRaw('LOWER(email) = ?', [mb_strtolower($who)])->orWhereRaw('LOWER(username) = ?', [mb_strtolower($who)])->first();
            return $row === null ? self::fail(400, 'No person found with that id, e-mail or username.') : [(array) $row];
        }
        if ($scope === 'random') {
            return DB::table('user')->where('Status', 1)->whereNotIn('User_Type', ['door', 'admin', 'admain', 'super_admin', 'super_admain'])
                ->inRandomOrder()->limit($count)->get()->map(fn ($r) => (array) $r)->all();
        }
        $rows = DB::table('user')->where('username', 'like', self::TEST_PREFIX . '%')->orderBy('created')->limit($count)->get()->map(fn ($r) => (array) $r)->all();
        return $rows ?: self::fail(400, 'There are no test people yet: create some first (Test people, above).');
    }

    private static function meals(mixed $raw): array
    {
        $meals = array_values(array_unique(array_filter(array_map(fn ($m) => self::meal($m), is_array($raw) ? $raw : []))));
        return $meals ?: self::fail(400, 'Pick at least one meal.');
    }

    private function injectUsers(array $b): array
    {
        $count = max(1, min(200, (int) ($b['count'] ?? 5)));
        $role = Roles::normalize((string) ($b['role'] ?? 'employee'));
        $department = trim((string) ($b['department'] ?? '')) ?: 'Test department';
        $meals = is_array($b['meals'] ?? null) ? array_map(fn ($m) => self::meal($m), $b['meals']) : ['breakfast', 'lunch', 'dinner'];
        $password = (string) config('mealboard.default_password');
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $ids = [];
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $suffix = substr(Ids::new(), 0, 6);
            $row = ControlRows::insert('user', [
                'username' => self::TEST_PREFIX . $suffix,
                'firstname' => 'Test',
                'lastname' => ucfirst($suffix),
                'Department' => $department,
                'Position' => 'Tester',
                'User_Type' => $role,
                'Status' => 1,
                'Breakfast' => in_array('breakfast', $meals, true),
                'Lunch' => in_array('lunch', $meals, true),
                'Dinner' => in_array('dinner', $meals, true),
            ], ['password' => $hash]);
            $ids[] = (string) $row['id'];
            $names[] = (string) $row['username'];
        }
        $this->track('user', $ids);
        return ['created' => $count, 'usernames' => $names, 'password' => $password];
    }

    private function injectBookings(array $b): array
    {
        $from = self::dayKey($b['from'] ?? null);
        $to = self::dayKey($b['to'] ?? null) ?: $from;
        if ($from === '' || $to < $from) {
            self::fail(400, 'Pick a valid day range.');
        }
        if (Days::fromKey($from)->diffInDays(Days::fromKey($to)) > 62) {
            self::fail(400, 'At most 62 days at once.');
        }
        $meals = self::meals($b['meals'] ?? []);
        $users = $this->pickUsers((string) ($b['scope'] ?? 'test'), (int) ($b['count'] ?? 10), (string) ($b['who'] ?? ''));
        $weekends = !empty($b['weekends']);

        $created = $merged = 0;
        $ids = [];
        for ($day = $from; $day <= $to; $day = Days::add($day, 1)) {
            if (!$weekends && Days::fromKey($day)->dayOfWeekIso >= 6) {
                continue;
            }
            foreach ($users as $user) {
                $data = [];
                foreach ($meals as $meal) {
                    $data[self::BOOKED[$meal]] = 1;
                }
                $existing = DB::table('oder')->where('user', $user['id'])->where('date', '>=', "$day 00:00:00.000")->where('date', '<=', "$day 23:59:59.999")->first();
                if ($existing !== null) {
                    ControlRows::update('oder', (string) $existing->id, $data);
                    $merged++;
                    continue;
                }
                $ids[] = (string) ControlRows::insert('oder', $data + ['user' => $user['id'], 'date' => $day])['id'];
                $created++;
            }
        }
        $this->track('oder', $ids);
        return ['created' => $created, 'mergedIntoExisting' => $merged, 'people' => count($users)];
    }

    /** Act like the door: mark some booked meals served and some missed (with strikes). */
    private function injectDoor(array $b): array
    {
        $day = self::dayKey($b['day'] ?? null);
        $meal = self::meal($b['meal'] ?? '');
        if ($day === '' || $meal === null) {
            self::fail(400, 'Pick a day and a meal.');
        }
        $servedPct = max(0, min(100, (int) ($b['served'] ?? 80)));
        $missedPct = max(0, min(100 - $servedPct, (int) ($b['missed'] ?? 20)));

        $q = DB::table('oder as o')->join('user as u', 'u.id', '=', 'o.user')->select('o.*')
            ->where('o.date', '>=', "$day 00:00:00.000")->where('o.date', '<=', "$day 23:59:59.999")
            ->where('o.' . self::BOOKED[$meal], 1)->where("o.attended_$meal", 0)->where("o.no_show_$meal", 0);
        if ((string) ($b['scope'] ?? 'test') !== 'all') {
            $q->where('u.username', 'like', self::TEST_PREFIX . '%');
        }
        $rows = $q->get()->shuffle()->values();

        $total = $rows->count();
        $served = (int) round($total * $servedPct / 100);
        $missed = min($total - $served, (int) round($total * $missedPct / 100));
        foreach ($rows as $i => $row) {
            if ($i < $served) {
                ControlRows::update('oder', (string) $row->id, ["attended_$meal" => 1]);
            } elseif ($i < $served + $missed) {
                ControlRows::update('oder', (string) $row->id, ["no_show_$meal" => 1]);
                Standing::addStrike((string) $row->user, $day, $meal, 'missed');
            }
        }
        return ['open bookings found' => $total, 'served' => $served, 'missed' => $missed, 'left untouched' => $total - $served - $missed];
    }

    private function injectHeartbeat(array $b): array
    {
        $door = trim((string) ($b['door'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $door)) {
            self::fail(400, 'Door id: 4-64 letters, digits, - or _.');
        }
        $now = Cron::nowMs();
        $pending = max(0, (int) ($b['pending'] ?? 0));
        DB::table('_mealboard_doors')->updateOrInsert(['door' => $door], [
            'name' => mb_substr(trim((string) ($b['name'] ?? '')), 0, 80),
            'last_seen' => $now - max(0, (int) ($b['seenMinutesAgo'] ?? 0)) * 60000,
            'pending' => $pending,
            'oldest_pending' => $pending > 0 ? $now - max(0, (int) ($b['oldestMinutes'] ?? 0)) * 60000 : null,
            'close_after' => max(0, min(180, (int) ($b['closeAfter'] ?? 0))),
        ]);
        $this->track('_doors', [$door]);
        return ['door' => $door, 'saved' => true];
    }

    /** Insert any row of an app table from JSON. */
    private function injectRow(array $b): array
    {
        $table = (string) ($b['table'] ?? '');
        if (!ControlRows::known($table)) {
            self::fail(400, 'Unknown table.');
        }
        $data = $b['data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data)) {
            self::fail(400, 'The row must be a JSON object.');
        }
        $extra = [];
        if ($table === 'user') {
            $extra['password'] = password_hash((string) ($data['password'] ?? '') ?: (string) config('mealboard.default_password'), PASSWORD_BCRYPT);
            unset($data['password']);
        }
        $row = ControlRows::insert($table, $data, $extra);
        if ($table === 'oder' && $row['user'] !== null) {
            $this->noShowChanges((string) $row['user'], null, $row);
        }
        $this->track($table, [(string) $row['id']]);
        return ControlRows::out($table, $row);
    }

    private function injectedSummary(): array
    {
        $out = [];
        foreach (Settings::get('control_injected') as $table => $ids) {
            $out[$table] = count($ids);
        }
        $out['test people (by username)'] = DB::table('user')->where('username', 'like', self::TEST_PREFIX . '%')->count();
        return $out;
    }

    /** Missed / not missed transitions of a booking decide the strikes (same as the API). */
    private function noShowChanges(string $userId, ?array $before, ?array $after): void
    {
        $day = Days::keyOf($after['date'] ?? $before['date'] ?? '');
        if ($day === '') {
            return;
        }
        foreach (self::BOOKED as $meal => $column) {
            $was = $before !== null && (int) ($before[$column] ?? 0) === 1 && (int) ($before["no_show_$meal"] ?? 0) === 1;
            $is = $after !== null && (int) ($after[$column] ?? 0) === 1 && (int) ($after["no_show_$meal"] ?? 0) === 1;
            if (!$was && $is) {
                Standing::addStrike($userId, $day, $meal, 'missed');
            } elseif ($was && !$is) {
                Standing::takeBackStrike($userId);
            }
        }
    }

    /** Delete a booking and give back the strikes its missed meals gave. */
    private function deleteBooking(array $row): void
    {
        ControlRows::delete('oder', (string) $row['id']);
        if ($row['user'] !== null) {
            $this->noShowChanges((string) $row['user'], $row, null);
        }
    }

    /** Remove everything the panel injected, plus every test_ person and their bookings. */
    private function cleanup(): array
    {
        $all = Settings::get('control_injected');
        $removed = [];
        $testUsers = DB::table('user')->where('username', 'like', self::TEST_PREFIX . '%')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $userIds = array_values(array_unique(array_merge($all['user'] ?? [], $testUsers)));

        $bookings = 0;
        foreach ($all['oder'] ?? [] as $id) {
            if (($row = ControlRows::find('oder', (string) $id)) !== null) {
                $this->deleteBooking($row);
                $bookings++;
            }
        }
        foreach ($userIds as $id) {
            $bookings += DB::table('oder')->where('user', $id)->delete();
            DB::table('sandwich_orders')->where('user', $id)->delete();
            DB::table('auth_tokens')->where('user_id', $id)->delete();
        }
        $removed['bookings'] = $bookings;

        // Strike / pause notifications sent only to test people.
        $notes = 0;
        foreach ($userIds as $id) {
            $notes += DB::table('notifications')->whereRaw('JSON_LENGTH(recipients) = 1 AND JSON_CONTAINS(recipients, JSON_QUOTE(?))', [$id])->delete();
        }
        $removed['notifications'] = $notes;
        $removed['people'] = $userIds === [] ? 0 : DB::table('user')->whereIn('id', $userIds)->delete();

        foreach ($all as $table => $ids) {
            if (in_array($table, ['user', 'oder'], true)) {
                continue;
            }
            $n = match (true) {
                $table === '_doors' => DB::table('_mealboard_doors')->whereIn('door', $ids)->delete(),
                $table === '_emails' => DB::table('pending_emails')->whereIn('id', $ids)->delete(),
                ControlRows::known($table) => DB::table($table)->whereIn('id', $ids)->delete(),
                default => 0,
            };
            $removed[['_doors' => 'doors', '_emails' => 'queued test e-mails'][$table] ?? $table] = $n;
        }

        Settings::put('control_injected', []);
        return $removed;
    }

    /* ------------------------------------------------------------------ */
    /* Tables                                                              */
    /* ------------------------------------------------------------------ */

    private function knownTable(string $table): string
    {
        return ControlRows::known($table) || isset(self::INTERNAL_TABLES[$table]) ? $table : self::fail(400, 'Unknown table.');
    }

    /** Column list with a simple type: text, bool, int, float, date, json (and password for people). */
    private function tableColumns(string $table): array
    {
        $def = ControlRows::TABLES[$table] ?? null;
        $out = [];
        foreach (DB::select("SHOW COLUMNS FROM `$table`") as $col) {
            $name = (string) $col->Field;
            if ($def !== null && in_array($name, $def['hidden'], true)) {
                continue;
            }
            $sqlType = strtolower((string) $col->Type);
            $type = 'text';
            if ($def !== null) {
                foreach (['bool', 'int', 'float', 'date', 'json'] as $t) {
                    if (in_array($name, $def[$t], true)) {
                        $type = $t;
                    }
                }
            } elseif (str_starts_with($sqlType, 'tinyint(1)')) {
                $type = 'bool';
            } elseif (preg_match('/^(big)?int|^smallint/', $sqlType)) {
                $type = 'int';
            } elseif (str_starts_with($sqlType, 'json')) {
                $type = 'json';
            } elseif (str_starts_with($sqlType, 'datetime')) {
                $type = 'date';
            }
            $out[] = [
                'name' => $name,
                'type' => $type,
                'long' => $type === 'text' && preg_match('/text|json/', $sqlType) === 1,
                'writable' => $def !== null && in_array($name, $def['write'], true),
            ];
        }
        if ($table === 'user') {
            // Never shown, but can be set: empty = keep the current one.
            $out[] = ['name' => 'password', 'type' => 'password', 'long' => false, 'writable' => true];
        }
        return $out;
    }

    private function tableList(): array
    {
        return array_map(fn ($name) => [
            'name' => $name,
            'system' => !ControlRows::known($name),
            'count' => DB::table($name)->count(),
        ], array_merge(array_keys(ControlRows::TABLES), array_keys(self::INTERNAL_TABLES)));
    }

    /** One page of rows: search, sort, paging; plus the column types and the names of the people the rows point to. */
    private function tableRows(array $b): array
    {
        $table = $this->knownTable((string) ($b['table'] ?? ''));
        $columns = $this->tableColumns($table);
        $names = array_column(array_filter($columns, fn ($c) => $c['type'] !== 'password'), 'name');
        $perPage = max(5, min(200, (int) ($b['perPage'] ?? 30)));
        $page = max(1, (int) ($b['page'] ?? 1));
        $search = trim((string) ($b['search'] ?? ''));

        $q = DB::table($table);
        if ($search !== '') {
            // Literal search: "test_" must not match "test-card" (_ and % are LIKE wildcards).
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $q->where(function ($w) use ($names, $like) {
                foreach ($names as $c) {
                    $w->orWhereRaw("CAST(`$c` AS CHAR) LIKE ?", [$like]);
                }
            });
        }
        $total = (clone $q)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $sort = (string) ($b['sort'] ?? '');
        if (in_array($sort, $names, true)) {
            $q->orderBy($sort, strtolower((string) ($b['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc');
        } else {
            $q->orderByRaw(ControlRows::known($table) ? '`created` DESC' : self::INTERNAL_TABLES[$table]);
        }
        $raw = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $keys = explode('|', self::INTERNAL_KEYS[$table] ?? 'id');
        $rows = [];
        foreach ($raw as $row) {
            $row = (array) $row;
            $out = ControlRows::known($table) ? ControlRows::out($table, $row) : $row;
            $out['_key'] = implode('|', array_map(fn ($k) => (string) $row[$k], $keys));
            $rows[] = $out;
        }

        $ids = [];
        foreach ($rows as $row) {
            foreach (self::PERSON_COLUMNS as $c) {
                if (!empty($row[$c]) && is_string($row[$c])) {
                    $ids[$row[$c]] = true;
                }
            }
        }
        $people = [];
        if ($ids !== []) {
            foreach (DB::table('user')->whereIn('id', array_keys($ids))->get(['id', 'firstname', 'lastname', 'username']) as $u) {
                $people[$u->id] = trim($u->firstname . ' ' . $u->lastname) ?: (string) $u->username;
            }
        }

        return [
            'table' => $table,
            'columns' => $columns,
            'rows' => $rows,
            'people' => (object) $people,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'editable' => ControlRows::known($table),
            'deletable' => ControlRows::known($table) || in_array($table, self::INTERNAL_DELETABLE, true),
        ];
    }

    /** Create (no id) or update (id) one row of an app table. */
    private function tableSave(array $b): array
    {
        $table = (string) ($b['table'] ?? '');
        if (!ControlRows::known($table)) {
            self::fail(400, 'Rows of this table cannot be edited here.');
        }
        $data = $b['data'] ?? null;
        if (!is_array($data)) {
            self::fail(400, 'Nothing to save.');
        }
        unset($data['id'], $data['created'], $data['updated'], $data['_key']);
        $id = trim((string) ($b['id'] ?? ''));

        $extra = [];
        if ($table === 'user') {
            $password = (string) ($data['password'] ?? '');
            unset($data['password']);
            if ($password !== '') {
                if (mb_strlen($password) < 8) {
                    self::fail(400, 'The password must be at least 8 characters.');
                }
                $extra['password'] = password_hash($password, PASSWORD_BCRYPT);
                $extra['tokenKey'] = bin2hex(random_bytes(15));
            } elseif ($id === '') {
                $extra['password'] = password_hash((string) config('mealboard.default_password'), PASSWORD_BCRYPT);
            }
            if (array_key_exists('email', $data)) {
                $email = trim((string) $data['email']);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    self::fail(400, 'Not an e-mail address: ' . $email);
                }
                $data['email'] = $email === '' ? null : $email;
            }
            if (array_key_exists('username', $data) && trim((string) $data['username']) === '') {
                $data['username'] = null;
            }
            if (isset($data['User_Type']) && $data['User_Type'] !== '') {
                $data['User_Type'] = Roles::normalize((string) $data['User_Type']);
            }
            if ($id === '' && !array_key_exists('Status', $data)) {
                $data['Status'] = 1;
            }
        }

        try {
            if ($id === '') {
                $row = ControlRows::insert($table, $data, $extra);
                if ($table === 'oder' && $row['user'] !== null) {
                    $this->noShowChanges((string) $row['user'], null, $row);
                }
            } else {
                $before = ControlRows::find($table, $id) ?? self::fail(404, 'This row no longer exists.');
                $row = ControlRows::update($table, $id, $data, $extra);
                if ($table === 'user' && isset($extra['password'])) {
                    DB::table('auth_tokens')->where('user_id', $id)->delete();
                }
                if ($table === 'oder' && $row['user'] !== null) {
                    $this->noShowChanges((string) $row['user'], $before, $row);
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                self::fail(400, 'Another row already uses that value (duplicate e-mail, username or key).');
            }
            throw $e;
        }
        return ['row' => ControlRows::out($table, $row)];
    }

    /** Delete one or several rows (ids = list of _key values). */
    private function tableDelete(array $b): array
    {
        $table = $this->knownTable((string) ($b['table'] ?? ''));
        $ids = isset($b['ids']) && is_array($b['ids']) ? $b['ids'] : [(string) ($b['id'] ?? '')];
        $ids = array_values(array_filter(array_map('strval', $ids), fn ($i) => $i !== ''));
        if ($ids === []) {
            self::fail(400, 'Nothing selected.');
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if (ControlRows::known($table)) {
                $row = ControlRows::find($table, $id);
                if ($row === null) {
                    continue;
                }
                if ($table === 'oder') {
                    $this->deleteBooking($row);
                } else {
                    if ($table === 'user') {
                        DB::table('auth_tokens')->where('user_id', $id)->delete();
                    }
                    ControlRows::delete($table, $id);
                }
                $deleted++;
                continue;
            }
            if (!in_array($table, self::INTERNAL_DELETABLE, true)) {
                self::fail(400, 'Rows of this table cannot be deleted here.');
            }
            $keys = explode('|', self::INTERNAL_KEYS[$table]);
            $values = count($keys) > 1 ? array_pad(explode('|', $id, count($keys)), count($keys), '') : [$id];
            $deleted += DB::table($table)->where(array_combine($keys, $values))->delete();
        }
        return ['deleted' => $deleted];
    }
}
