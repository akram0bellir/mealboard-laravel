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

function is_rtl(): bool
{
    return app()->getLocale() === 'ar';
}
