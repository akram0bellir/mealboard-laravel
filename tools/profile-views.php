<?php
// Which views cost the most on a page? Times every view render (its own time, without the views inside it).
//   php -d opcache.enable_cli=1 tools/profile-views.php /users akrambellirwork1@gmail.com [runs]
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\View\Engine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$path = $argv[1] ?? '/week';
$email = $argv[2] ?? 'demo2@demo.mealboard.test';
$runs = (int) ($argv[3] ?? 8);
require __DIR__ . '/../vendor/autoload.php';

final class TimingEngine implements Engine
{
    public static array $own = [];
    public static array $count = [];
    private static array $stack = [];

    public function __construct(private Engine $inner) {}

    public function get($path, array $data = [])
    {
        $start = hrtime(true);
        self::$stack[] = 0;
        try {
            return $this->inner->get($path, $data);
        } finally {
            $children = array_pop(self::$stack);
            $total = hrtime(true) - $start;
            $name = str_replace([realpath(__DIR__ . '/../resources/views') . DIRECTORY_SEPARATOR, '.blade.php', '\\'], ['', '', '/'], $path);
            self::$own[$name] = (self::$own[$name] ?? 0) + ($total - $children);
            self::$count[$name] = (self::$count[$name] ?? 0) + 1;
            if (self::$stack) {
                self::$stack[count(self::$stack) - 1] += $total;
            }
        }
    }
}

$total = 0.0;
// One app for every run (a Laravel app cannot be rebuilt in the same process); the first runs warm it up.
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$app->instance('request', Request::create($path, 'GET'));
$kernel->bootstrap();
$resolver = $app['view.engine.resolver'];
$blade = $resolver->resolve('blade');
$resolver->register('blade', fn () => new TimingEngine($blade));
for ($i = 0; $i < $runs; $i++) {
    $request = Request::create($path, 'GET');
    $app->instance('request', $request);
    if ($i === 2) {
        TimingEngine::$own = [];
        TimingEngine::$count = [];
        $total = 0.0;
    }
    Auth::login(\App\Models\User::where('email', $email)->first());
    $t = hrtime(true);
    $response = $kernel->handle($request);
    if ($i >= 2) {
        $total += (hrtime(true) - $t) / 1e6;
    }
    if ($response->getStatusCode() !== 200) {
        echo "status {$response->getStatusCode()}
";
    }
    $kernel->terminate($request, $response);
}
$measured = $runs - 2;
arsort(TimingEngine::$own);
printf("%s: %.1f ms a request (%d runs)\n", $path, $total / $measured, $measured);
printf("%-48s %8s %8s %9s\n", 'view', 'renders', 'ms', 'µs each');
foreach (array_slice(TimingEngine::$own, 0, 14, true) as $name => $ns) {
    $n = TimingEngine::$count[$name] / $measured;
    printf("%-48s %8d %8.2f %9.1f\n", $name, $n, $ns / 1e6 / $measured, $ns / 1e3 / $measured / max($n, 1));
}
