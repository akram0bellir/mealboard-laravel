<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The page language is chosen per device (cookie "lang"): en, fr or ar (right-to-left). */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $lang = (string) $request->cookie('lang', 'en');
        app()->setLocale(array_key_exists($lang, MEALBOARD_LANGS) ? $lang : 'en');

        return $next($request);
    }
}
