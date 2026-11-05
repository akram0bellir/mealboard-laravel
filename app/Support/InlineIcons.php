<?php

namespace App\Support;

/**
 * Compile-time icons. `<x-lucide-check class="size-4" aria-hidden="true" />` is replaced by the exact SVG the
 * blade-icons component would print, once, when the view is compiled. Tags with anything dynamic (`:attr`,
 * `{{ }}`, `@...`, a valueless attribute) are left to the normal component.
 */
class InlineIcons
{
    private const TAG = '/<x-lucide-([a-z0-9-]+)((?:\s+[^\s=>\/]+(?:="[^"]*")?)*)\s*\/>/';

    public static function compile(string $template): string
    {
        if (!str_contains($template, '<x-lucide-')) {
            return $template;
        }
        return preg_replace_callback(self::TAG, function (array $m) {
            preg_match_all('/([^\s=]+)(?:="([^"]*)")?/', $m[2], $pairs, PREG_SET_ORDER);
            $attributes = [];
            foreach ($pairs as $pair) {
                $name = $pair[1];
                // style: Blade rewrites it ("a:1" -> "a:1;"), so leave those few to the component too
                if (!isset($pair[2]) || $name === 'style' || $name[0] === ':' || $name[0] === '@' || str_contains($pair[2], '{{') || str_contains($pair[2], '{!!') || str_contains($pair[2], '@')) {
                    return $m[0]; // dynamic or unusual: keep the component
                }
                $attributes[$name] = $pair[2];
            }
            $class = $attributes['class'] ?? '';
            unset($attributes['class']);
            try {
                return svg('lucide-' . $m[1], $class, $attributes)->toHtml();
            } catch (\Throwable) {
                return $m[0]; // unknown icon: let the component report it
            }
        }, $template);
    }
}
