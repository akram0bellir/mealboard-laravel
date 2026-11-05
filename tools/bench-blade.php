<?php
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Blade;
$cases = [
  'plain html' => '<span class="size-4">x</span>',
  'lucide icon' => '<x-lucide-check class="size-4" aria-hidden="true" />',
  'x-admin.btn' => '<x-admin.btn size="sm" variant="soft">Hi</x-admin.btn>',
  'x-admin.avatar' => '<x-admin.avatar name="Ana Bee" />',
  'x-admin.pill' => '<x-admin.pill>Hi</x-admin.pill>',
  't() call' => '{{ t("Booked") }}',
];
foreach ($cases as $name => $tpl) {
  $loop = '@for ($i = 0; $i < 500; $i++)' . $tpl . '@endfor';
  Blade::render($loop); // compile + warm
  $t = microtime(true); for ($r = 0; $r < 3; $r++) Blade::render($loop); $ms = (microtime(true) - $t) * 1000 / 3 / 500;
  printf("%-16s %6.1f µs each\n", $name, $ms * 1000);
}
