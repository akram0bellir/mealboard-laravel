<?php
require __DIR__ . '/../vendor/autoload.php'; $app = require __DIR__ . '/../bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Blade;
$row = '@for ($i = 0; $i < 300; $i++)<x-admin.btn :href="\'/users?edit=\' . $i" size="icon-sm" variant="ghost" aria-label="{{ t(\'Edit {0}\', [0 => \'Ana\']) }}"><x-lucide-pencil /></x-admin.btn>@endfor';
Blade::render($row); $t = hrtime(true); Blade::render($row); printf("btn with dynamic href + icon: %.1f µs each\n", (hrtime(true) - $t) / 1e3 / 300);
$row2 = '@for ($i = 0; $i < 300; $i++)<x-admin.avatar :name="\'Ana Bee\'" />@endfor';
Blade::render($row2); $t = hrtime(true); Blade::render($row2); printf("avatar: %.1f µs each\n", (hrtime(true) - $t) / 1e3 / 300);
