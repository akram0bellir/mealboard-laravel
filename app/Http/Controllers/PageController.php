<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/** Pages that are not built yet: they show the real top bar and a "coming next" card. */
class PageController extends Controller
{
    public function soon(string $title): View
    {
        return view('pages.soon', ['title' => $title]);
    }
}
