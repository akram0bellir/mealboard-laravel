<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * The compiled file of a view is named after a hash of the view's path. On Windows the same view is reached as
 * "C:\...\views\auth\login.blade.php" by `artisan view:cache` and as "C:\...\views/auth/login.blade.php" by the web
 * server, so the two hashes differ: the views compiled in advance are never used, every view is compiled again
 * while people use the site, and parallel compiles of the same view fail ("rename ... Access denied").
 * Hashing the path with one kind of slash fixes both.
 */
class WindowsSafeBladeCompiler extends BladeCompiler
{
    public function getCompiledPath($path)
    {
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', (string) $this->basePath);
        return $this->cachePath . '/' . hash('xxh128', 'v2' . Str::after($path, $base)) . '.' . $this->compiledExtension;
    }
}
