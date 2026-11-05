<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup;
use App\Services\Cron;
use App\Services\Mailer;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Super admin: is everything running? (health), the cron runs, the door PCs, the e-mail queue, the backups —
 * with buttons to run the jobs now. The useful part of the old /control page.
 */
class SystemController extends Controller
{
    public function index(Request $request): View
    {
        $this->superOnly($request);
        $now = Cron::nowMs();
        $log = Cron::log();
        $backups = Backup::list();
        $lastBackup = collect($backups)->first(fn ($b) => str_ends_with($b['name'], '.sql.gz'));
        $queue = [
            'waiting' => DB::table('pending_emails')->whereNull('sent_at')->count(),
            'failed' => DB::table('pending_emails')->whereNull('sent_at')->where('attempts', '>', 0)->count(),
            'sent' => DB::table('pending_emails')->whereNotNull('sent_at')->count(),
        ];
        $doors = Cron::activeDoors();
        $rules = \App\Services\BookingRules::current();

        $health = [];
        $add = function (string $name, string $state, string $detail) use (&$health) {
            $health[] = ['name' => $name, 'state' => $state, 'detail' => $detail];
        };
        try {
            DB::select('select 1');
            $add('Database', 'ok', 'Connected (' . DB::connection()->getDatabaseName() . ').');
        } catch (\Throwable $e) {
            $add('Database', 'bad', $e->getMessage());
        }
        $smtp = DB::table('smtp')->first();
        $smtpFilled = trim((string) ($smtp->SMTP_server_host ?? '')) !== '' && trim((string) ($smtp->Sender_address ?? '')) !== '';
        if (!config('mealboard.send_mail')) {
            $add('E-mail', 'warn', 'Switched off in .env (MEALBOARD_SEND_MAIL=false): nothing is sent from this copy.');
        } elseif ($smtpFilled) {
            $add('E-mail', 'ok', 'SMTP is set up (' . $smtp->SMTP_server_host . ').');
        } else {
            $add('E-mail', 'warn', 'No SMTP settings yet: notifications stay in the queue.');
        }
        $last = $log[0] ?? null;
        $age = $last ? intdiv($now - (int) $last['at'], 60000) : null;
        $add('Cron', $last === null ? 'bad' : ($age > 15 ? 'bad' : (!$last['ok'] ? 'warn' : 'ok')), $last === null ? 'It has never run. Install the Windows tasks (install-tasks.bat).' : sprintf('Last run %s ago (%s)%s.', $this->age($age), $last['source'], $last['ok'] ? '' : ', with errors'));
        $add('Backups', $lastBackup === null ? 'bad' : (time() - $lastBackup['at'] > 8 * 3600 ? 'warn' : 'ok'), $lastBackup === null ? 'No database backup found yet.' : sprintf('Last database backup %s ago (%s).', $this->age(intdiv(time() - $lastBackup['at'], 60)), $this->size($lastBackup['size'])));
        $uploads = (string) config('mealboard.upload_dir');
        $add('Photos folder', is_dir($uploads) && is_writable($uploads) ? 'ok' : 'bad', is_dir($uploads) ? (is_writable($uploads) ? $uploads : 'Not writable: ' . $uploads) : 'Missing: ' . $uploads);
        $add('PHP extensions', class_exists(\ZipArchive::class) && extension_loaded('gd') ? 'ok' : 'warn', 'zip (photo backups): ' . (class_exists(\ZipArchive::class) ? 'on' : 'OFF') . ' · gd (thumbnails): ' . (extension_loaded('gd') ? 'on' : 'off'));
        $add('Door PCs', $doors ? 'ok' : 'warn', $doors ? count($doors) . ' seen in the last 3 days.' : 'None has reported in the last 3 days: automatic no-shows wait for the doors only when one has.');
        $add('Automatic no-shows', $rules->autoNoShowFrom !== '' ? 'ok' : 'warn', $rules->autoNoShowFrom !== '' ? 'On, from ' . $rules->autoNoShowFrom . '.' : 'Off (Settings → No-show rules).');
        $add('E-mail queue', $queue['failed'] ? 'warn' : 'ok', "{$queue['waiting']} waiting, {$queue['failed']} failed at least once, {$queue['sent']} sent.");

        return view('admin.system', [
            'health' => $health,
            'log' => array_slice($log, 0, 12),
            'doors' => $doors,
            'now' => $now,
            'queue' => $queue,
            'emails' => DB::table('pending_emails')->orderByDesc('created')->limit(15)->get(),
            'backups' => $backups,
            'backupLog' => Backup::logTail(),
            'status' => session('system_status'),
        ]);
    }

    public function runCron(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        $result = Cron::run('system');
        $marked = array_sum(array_column($result['autoNoShow']['marked'] ?? [], 'marked'));
        return $this->back(!isset($result['errors']), isset($result['errors'])
            ? implode(' | ', $result['errors'])
            : sprintf('Cron ran: %d meal(s) marked as missed, %d e-mail(s) sent, %d failed.', $marked, $result['emails']['sent'], $result['emails']['failed']));
    }

    public function sendEmails(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        if (!Mailer::isOn()) {
            return $this->back(false, 'E-mail is switched off (no SMTP settings, or MEALBOARD_SEND_MAIL=false).');
        }
        [$sent, $failed] = Mailer::flushQueue(50);
        return $this->back($failed === 0, "E-mails sent: {$sent}, failed: {$failed}.");
    }

    public function backup(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        try {
            $r = Backup::run();
        } catch (\Throwable $e) {
            return $this->back(false, 'Backup failed: ' . $e->getMessage());
        }
        return $this->back(!$r['warnings'], 'Backup written: ' . basename($r['file']) . ' (' . $this->size($r['size']) . ').' . ($r['warnings'] ? ' ' . implode(' ', $r['warnings']) : ''));
    }

    private function back(bool $ok, string $text): RedirectResponse
    {
        return redirect('/system')->with('system_status', [$ok, $text]);
    }

    private function superOnly(Request $request): void
    {
        abort_unless(Roles::isSuper($request->user()->User_Type), 403);
    }

    private function age(int $minutes): string
    {
        return $minutes < 1 ? 'less than a minute' : ($minutes < 90 ? "{$minutes} min" : ($minutes < 2880 ? round($minutes / 60) . ' h' : round($minutes / 1440) . ' days'));
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024, 0) . ' KB';
    }
}
