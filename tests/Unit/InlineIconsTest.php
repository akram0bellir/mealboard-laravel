<?php

namespace Tests\Unit;

use App\Support\InlineIcons;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class InlineIconsTest extends TestCase
{
    /** Every fixed icon tag in every view prints exactly what the blade-icons component prints. */
    public function test_inlined_icons_match_the_component_everywhere(): void
    {
        $tags = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                preg_match_all('/<x-lucide-[a-z0-9-]+(?:\s+[^\s=>\/]+(?:="[^"]*")?)*\s*\/>/', file_get_contents($file->getPathname()), $m);
                foreach ($m[0] as $tag) {
                    $tags[$tag] = true;
                }
            }
        }
        $this->assertGreaterThan(50, count($tags), 'the views use many icons');
        $inlined = 0;
        foreach (array_keys($tags) as $tag) {
            $compiled = InlineIcons::compile($tag);
            if ($compiled === $tag) {
                continue; // dynamic: left to the component
            }
            $inlined++;
            // the same tag through the real component (x-dynamic-component is never inlined)
            preg_match('/<x-lucide-([a-z0-9-]+)(.*)\/>/s', $tag, $p);
            $viaComponent = Blade::render('<x-dynamic-component component="lucide-' . $p[1] . '"' . $p[2] . '/>');
            $this->assertSame(trim($viaComponent), trim($compiled), $tag);
        }
        $this->assertGreaterThan(50, $inlined);
    }

    public function test_dynamic_tags_are_left_alone(): void
    {
        foreach (['<x-lucide-check :class="$c" />', '<x-lucide-check aria-label="{{ $x }}" />', '<x-lucide-check hidden />', '<x-lucide-not-a-real-icon class="a" />'] as $tag) {
            $this->assertSame($tag, InlineIcons::compile($tag));
        }
    }

    public function test_a_page_still_renders_its_icons(): void
    {
        $html = Blade::render('<p><x-lucide-check class="size-4" aria-hidden="true" /> ok</p>');
        $this->assertStringContainsString('<svg aria-hidden="true" class="size-4"', $html);
        $this->assertStringNotContainsString('x-lucide', $html);
    }
}
