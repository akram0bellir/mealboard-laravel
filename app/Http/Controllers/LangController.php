<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LangController extends Controller
{
    /** Remembers the language on this device (a year), then returns to the page the person was on. */
    public function set(Request $request): RedirectResponse
    {
        $lang = (string) $request->input('lang');
        abort_unless(array_key_exists($lang, MEALBOARD_LANGS), 422);

        return back()->withCookie(cookie('lang', $lang, 60 * 24 * 365));
    }
}
