<?php

/** The page languages. English text is the key; fr/ar come from lang/{code}.json. */
const MEALBOARD_LANGS = [
    'en' => ['label' => 'English', 'short' => 'EN'],
    'fr' => ['label' => 'Français', 'short' => 'FR'],
    'ar' => ['label' => 'العربية', 'short' => 'ع'],
];

/** Translates `$text` into the current language; `{name}` placeholders are filled from `$vars`. */
function t(string $text, array $vars = []): string
{
    static $dicts = [];

    $lang = app()->getLocale();
    $out = $text;
    if ($lang !== 'en') {
        $dicts[$lang] ??= json_decode((string) @file_get_contents(base_path("lang/{$lang}.json")), true) ?: [];
        $out = $dicts[$lang][$text] ?? $text;
    }
    foreach ($vars as $key => $value) {
        $out = str_replace('{' . $key . '}', (string) $value, $out);
    }
    return $out;
}

/**
 * A Lucide icon whose name is only known at run time: the same SVG as <x-lucide-...>, without the cost of
 * <x-dynamic-component> (which compiles a template on every use). Kept in memory for the request.
 */
function icon(string $name, string $class = '', array $attributes = []): \Illuminate\Support\HtmlString
{
    static $cache = [];
    $key = $name . '|' . $class . '|' . http_build_query($attributes);
    return $cache[$key] ??= new \Illuminate\Support\HtmlString(trim(svg('lucide-' . $name, $class, $attributes)->toHtml()));
}

function is_rtl(): bool
{
    return app()->getLocale() === 'ar';
}
