<?php

use App\Services\Backup;
use App\Services\Cron;
use App\Services\DayOrders;
use App\Services\Mailer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Everything the cron does: automatic no-shows, the daily order list, the waiting e-mails.
Artisan::command('mealboard:cron', function () {
    $result = Cron::run('cli');
    $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (isset($result['errors'])) {
        $this->error('Cron finished with errors: ' . implode(' | ', $result['errors']));
        return 1;
    }
})->purpose('Run the scheduled jobs: automatic no-shows, daily list, e-mails');

// Send the e-mails waiting in the queue (notifications).
Artisan::command('mealboard:send-emails {--limit=50}', function () {
    [$sent, $failed] = Mailer::flushQueue((int) $this->option('limit'));
    $this->info("E-mails sent: {$sent}, failed: {$failed}.");
})->purpose('Send the queued notification e-mails');

// The daily order list (Settings → Daily order list).
Artisan::command('mealboard:daily-orders', function () {
    $this->info(DayOrders::runDaily());
})->purpose('E-mail the daily order list when it is time');

// Database dump + photos zip + clean-up of old backups.
Artisan::command('mealboard:backup', function () {
    try {
        $r = Backup::run();
    } catch (\Throwable $e) {
        $this->error('Backup failed: ' . $e->getMessage());
        return 1;
    }
    $this->info('Backup written: ' . $r['file']);
    foreach ($r['warnings'] as $w) {
        $this->warn($w);
    }
})->purpose('Back up the database and the photos');

// Set a new password for one account and log it out everywhere (replaces tools/set-password.php).
Artisan::command('mealboard:set-password {login : e-mail or username} {password : at least 8 characters}', function (string $login, string $password) {
    if (mb_strlen($password) < 8) {
        $this->error('The password must be at least 8 characters.');
        return 1;
    }
    $login = mb_strtolower(trim($login));
    $user = DB::table('user')->whereRaw('LOWER(email) = ?', [$login])->orWhereRaw('LOWER(username) = ?', [$login])->first();
    if ($user === null) {
        $this->error('No account with that e-mail or username.');
        return 1;
    }
    DB::table('user')->where('id', $user->id)->update(['password' => password_hash($password, PASSWORD_BCRYPT), 'tokenKey' => bin2hex(random_bytes(15)), 'updated' => App\Support\Ids::now()]);
    DB::table('auth_tokens')->where('user_id', $user->id)->delete();
    DB::table('login_attempts')->where('attempt_key', 'like', $login . '|%')->delete();
    $this->info('Password changed for ' . ($user->email ?: $user->username) . " ({$user->User_Type}).");
})->purpose('Set a new password for one account');

// Old sessions and expired API tokens: swept once a day instead of during visitors' requests.
Artisan::command('mealboard:sessions-gc', function () {
    $sessions = app('session')->driver()->getHandler()->gc((int) config('session.lifetime') * 60);
    $tokens = DB::table('auth_tokens')->whereRaw('expires < UTC_TIMESTAMP(3)')->delete();
    $attempts = DB::table('login_attempts')->where('first_at', '<', gmdate('Y-m-d H:i:s', time() - 86400))->whereNull('blocked_until')->delete();
    $this->info("Removed {$sessions} old sessions, {$tokens} expired API tokens, {$attempts} old failed-login records.");
})->purpose('Remove old sessions, expired API tokens and old failed-login records');
Schedule::command('mealboard:sessions-gc')->dailyAt('03:30');

// The same jobs as the old Windows tasks: the cron every 5 minutes, a backup every 6 hours.
// (A host that runs `php artisan schedule:run` every minute needs nothing else.)
Schedule::command('mealboard:cron')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('mealboard:backup')->everySixHours()->withoutOverlapping();
