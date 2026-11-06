<?php
// Saves the HTML of the main pages, so a speed change can be checked to print exactly the same thing.
//   php tools/snapshot-pages.php <folder>        then compare two folders (e.g. with `diff -r`).
// CSRF tokens and other per-request values are blanked out.
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$dir = $argv[1] ?? 'snapshots';
@mkdir($dir, 0777, true);
require __DIR__ . '/../vendor/autoload.php';

$employee = 'demo2@demo.mealboard.test';
$admin = 'akrambellirwork1@gmail.com';
$super = 'akrambellirwork4@gmail.com';
$pages = [
    [null, '/'],
    [$employee, '/week'], [$employee, '/notifications'], [$employee, '/profile'], [$employee, '/report'],
    [$admin, '/admin'], [$admin, '/meals'], [$admin, '/users'], [$admin, '/attendance?date=2026-11-04'], [$admin, '/serve'],
    [$admin, '/reports'], [$admin, '/statistics?period=month&anchor=2026-09-15'], [$admin, '/invoices?month=2026-09'], [$admin, '/penalties'],
    [$admin, '/admain_notifications'], [$admin, '/diners?date=2026-11-04'], [$super, '/settings'], [$super, '/system'],
    [$admin, '/admin?email=1'], [$admin, '/serve?relay=1'],
];
$only = $argv[2] ?? null; // one page per PHP process (Laravel apps cannot be rebuilt in the same process)
$n = -1;
foreach (['en', 'fr', 'ar'] as $lang) {
    foreach ($pages as [$email, $path]) {
        if ($lang !== 'en' && !in_array($path, ['/', '/week', '/admin', '/users'], true)) {
            continue;
        }
        $n++;
        if ($only === null) {
            passthru(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=1 ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($dir) . ' ' . $n);
            continue;
        }
        if ((int) $only !== $n) {
            continue;
        }
        $app = require __DIR__ . '/../bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        $request = Request::create($path, 'GET', [], ['lang' => $lang]);
        $app->instance('request', $request);
        $kernel->bootstrap();
        if ($email) {
            Auth::login(\App\Models\User::where('email', $email)->first());
        }
        $html = $kernel->handle($request)->getContent();
        $html = preg_replace(['/name="_token" value="[^"]*"/', '/content="[A-Za-z0-9]{40}"/', '/data-token="[^"]*"/'], ['name="_token" value=""', 'content=""', 'data-token=""'], $html);
        // things that change with the clock
        $html = preg_replace('/\d{1,2} \w{3} \d{2}:\d{2}:\d{2}/u', 'TIME', $html);
        $name = $lang . '_' . trim(preg_replace('/[^a-z0-9]+/i', '_', $path), '_') ?: 'home';
        file_put_contents("$dir/$name.html", $html);
        $app->flush();
    }
}
echo "saved to $dir\n";
