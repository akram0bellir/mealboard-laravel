<?php
// Cost of small calls made in loops by the views. php -d opcache.enable_cli=1 tools/bench-calls.php
require __DIR__ . '/../vendor/autoload.php'; $app = require __DIR__ . '/../bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Support\Days;
$bench = function (string $name, callable $f, int $n = 2000) { $f(); $t = hrtime(true); for ($i = 0; $i < $n; $i++) $f(); printf("%-40s %7.1f µs\n", $name, (hrtime(true) - $t) / 1e3 / $n); };
$bench('Days::today()', fn () => Days::today());
$bench('Days::fromKey()', fn () => Days::fromKey('2026-11-05'));
$bench("fromKey()->format('j M')", fn () => Days::fromKey('2026-11-05')->format('j M'));
$bench("fromKey()->translatedFormat('j M') en", fn () => Days::fromKey('2026-11-05')->translatedFormat('j M'));
app()->setLocale('fr');
$bench("translatedFormat('l j F') fr", fn () => Days::fromKey('2026-11-05')->translatedFormat('l j F'));
$bench("t() fr", fn () => t('Booked'));
app()->setLocale('en');
$bench("t() with a placeholder", fn () => t('{0} strikes', [0 => 3]));
$bench('collect()->filter()->implode()', fn () => collect(['a', 'b'])->filter()->implode(' · '));
$bench('BookingRules::current()', fn () => App\Services\BookingRules::current());
$bench('e() escape', fn () => e('Hello <b>'));
