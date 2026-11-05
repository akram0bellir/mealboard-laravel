<?php
// Where does a page spend its time? Boots Laravel once per request (like Apache does) and reports:
//   php -d opcache.enable_cli=1 tools/profile-page.php /week demo2@demo.mealboard.test [runs]
// Prints the total, the database time and query count, and the view rendering time.
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$path = $argv[1] ?? '/week';
$email = $argv[2] ?? 'demo2@demo.mealboard.test';
$runs = (int) ($argv[3] ?? 30);
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';

$times = ['total' => [], 'boot' => [], 'db' => [], 'views' => [], 'queries' => []];
for ($i = 0; $i < $runs; $i++) {
    $t0 = microtime(true);
    $app = require __DIR__ . '/../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $request = Request::create($path, 'GET');
    $app->instance('request', $request);
    $kernel->bootstrap();
    $tBoot = microtime(true);
    $db = 0.0; $q = 0; $view = 0.0; $viewDepth = 0; $viewStart = 0.0;
    DB::listen(function ($e) use (&$db, &$q) { $db += $e->time; $q++; });
    $views = 0;
    Event::listen('composing:*', function () use (&$views) { $views++; });
    $user = \App\Models\User::where('email', $email)->first();
    Auth::login($user);
    $response = $kernel->handle($request);
    $t1 = microtime(true);
    $kernel->terminate($request, $response);
    $times['total'][] = ($t1 - $t0) * 1000;
    $times['boot'][] = ($tBoot - $t0) * 1000;
    $times['db'][] = $db;
    $times['queries'][] = $q;
    $times['views'][] = $views;
    if ($i === 0) {
        echo "status {$response->getStatusCode()}, " . strlen($response->getContent()) . " bytes\n";
    }
    $app->flush();
    unset($app, $kernel);
}
$median = function (array $a) { sort($a); return $a[intdiv(count($a), 2)]; };
// the first runs warm OPcache: drop them
foreach ($times as $k => $v) {
    $times[$k] = array_slice($v, min(5, count($v) - 1));
}
printf("%s  total %.1f ms | boot %.1f ms | database %.1f ms in %d queries | the rest %.1f ms | %d views rendered\n",
    $path, $median($times['total']), $median($times['boot']), $median($times['db']), $median($times['queries']), $median($times['total']) - $median($times['boot']) - $median($times['db']), $median($times['views']));
