<?php
// Time the parts of the week page (app loaded once, like a warm server).
require __DIR__ . '/../vendor/autoload.php'; $app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); $app->instance('request', Illuminate\Http\Request::create('/week')); $kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$u = App\Models\User::where('email', $argv[1] ?? 'demo2@demo.mealboard.test')->first();
Illuminate\Support\Facades\Auth::login($u);
$q = []; DB::listen(function ($e) use (&$q) { $q[] = [round($e->time, 2), substr(preg_replace('/\s+/', ' ', $e->sql), 0, 110)]; });
$t = function ($name, $f, $n = 50) { $f(); $s = hrtime(true); for ($i = 0; $i < $n; $i++) $f(); printf("%-34s %7.2f ms\n", $name, (hrtime(true) - $s) / 1e6 / $n); };
$t('WeekPage::build', fn () => App\Services\WeekPage::build($u));
$t('Standing::read', fn () => App\Services\Standing::read($u, true));
$t('MealPlan::for', fn () => App\Services\MealPlan::for($u));
$t('MealTimes::for', fn () => App\Services\MealTimes::for($u, ['breakfast','lunch','dinner'], App\Services\BookingRules::current()));
$t('Bookings::forUser', fn () => App\Services\Bookings::forUser($u->id));
$t('Standing::strikes (top bar)', fn () => App\Services\Standing::strikes($u));
$q = []; App\Services\WeekPage::build($u);
echo "\nqueries in one WeekPage::build:\n"; foreach ($q as [$ms, $sql]) printf("  %6.2f ms  %s\n", $ms, $sql);
$q = []; $r = $kernel->handle(Illuminate\Http\Request::create('/week'));
echo "\nqueries in the whole /week request (status {$r->getStatusCode()}):\n"; foreach ($q as [$ms, $sql]) printf("  %6.2f ms  %s\n", $ms, $sql);
