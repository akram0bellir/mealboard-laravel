<?php

namespace App\Providers;

use App\Support\InlineIcons;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // <x-lucide-*> icons with fixed attributes become plain SVG when a view is compiled, instead of a
        // component rendered on every request (about 20 µs each, and pages draw hundreds).
        Blade::precompiler([InlineIcons::class, 'compile']);
    }
}
