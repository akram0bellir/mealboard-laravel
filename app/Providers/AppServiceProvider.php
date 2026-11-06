<?php

namespace App\Providers;

use App\Support\InlineIcons;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Same as Laravel's own binding (ViewServiceProvider), with a compiler that names compiled views the same
        // way whether the path has \ or / (see WindowsSafeBladeCompiler).
        $this->app->singleton('blade.compiler', function ($app) {
            return tap(new \App\Support\WindowsSafeBladeCompiler(
                $app['files'],
                $app['config']['view.compiled'],
                $app['config']->get('view.relative_hash', false) ? $app->basePath() : '',
                $app['config']->get('view.cache', true),
                $app['config']->get('view.compiled_extension', 'php'),
                $app['config']->get('view.check_cache_timestamps', true),
            ), function ($blade) {
                $blade->component('dynamic-component', \Illuminate\View\DynamicComponent::class);
            });
        });
    }

    public function boot(): void
    {
        // <x-lucide-*> icons with fixed attributes become plain SVG when a view is compiled, instead of a
        // component rendered on every request (about 20 µs each, and pages draw hundreds).
        // (Must run before Blade turns <x-...> tags into components, hence not Blade::precompiler.)
        Blade::prepareStringsForCompilationUsing(fn (string $template) => InlineIcons::compile($template));
        // @icon($name, 'size-4', ['aria-hidden' => 'true']): an icon whose name is known at run time. A directive
        // (not {{ }}) so the line break after it is dropped exactly like after the <x-lucide> tag it replaces.
        Blade::directive('icon', fn (string $args) => "<?php echo icon({$args}); ?>");
    }
}
