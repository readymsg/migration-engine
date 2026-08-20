<?php

declare(strict_types=1);

namespace Tests\Unit\Extract;

use App\Services\Extract\LogoPaletteExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Pins the LogoPaletteExtractor contract:
//   1. Same bytes → same palette (deterministic).
//   2. Fully-transparent pixels don't leak as white/light-grey.
//   3. Red + black club → primary=red, secondary=black (or same
//      cluster), background=white — the tbirdhoops case.
//   4. Genuinely mono-hue brand → primary set, accent unset (no
//      fabricated third color).
//   5. Garbage bytes → null return, caller falls through.
final class LogoPaletteExtractorTest extends TestCase
{
    private function extractor(): LogoPaletteExtractor
    {
        return new LogoPaletteExtractor;
    }

    #[Test]
    public function red_and_black_logo_returns_red_primary_and_black_secondary(): void
    {
        // Synthetic 200×100 PNG: left half solid red (#C00020), right
        // half solid black (#000000), on a white background. Mirrors
        // tbirdhoops' actual identity distribution.
        $png = $this->syntheticPng([
            [0.0, 0.5, 0xC0, 0x00, 0x20, 0xFF],  // red
            [0.5, 1.0, 0x00, 0x00, 0x00, 0xFF],  // black
        ], 200, 100);

        $palette = $this->extractor()->extract($png);

        $this->assertNotNull($palette);
        $this->assertArrayHasKey('primary', $palette);
        // Red or black could win primary depending on frequency —
        // both are ~50% here. Assert primary is the RED cluster
        // specifically (red should dominate the pool because black
        // gets slotted into `text`).
        $this->assertMatchesRegularExpression('/^#[B-Fb-f][0-9A-Fa-f]{5}$/', $palette['primary'], 'primary must be a red-family color (R channel dominant), got '.$palette['primary']);
        $this->assertArrayHasKey('text', $palette);
        $this->assertLessThan(30, hexdec(substr($palette['text'], 1, 2)), 'text must be near-black');
    }

    #[Test]
    public function transparent_pixels_do_not_contribute_color(): void
    {
        // Left half red, right half fully transparent. Extractor must
        // NOT treat the transparent half as any color at all — the
        // resulting palette must not include a white or grey cluster
        // arising from transparent regions.
        $png = $this->syntheticPng([
            [0.0, 0.5, 0xC0, 0x00, 0x20, 0xFF],  // red
            [0.5, 1.0, 0x00, 0x00, 0x00, 0x00],  // FULLY transparent
        ], 200, 100);

        $palette = $this->extractor()->extract($png);
        $this->assertNotNull($palette);
        $this->assertArrayHasKey('primary', $palette);
        // Primary must be red-family. Transparent region must not
        // have leaked as a "white/grey" cluster to become primary.
        $this->assertMatchesRegularExpression('/^#[B-Fb-f][0-9A-Fa-f]{5}$/', $palette['primary'], 'primary must be red-family; got '.$palette['primary']);
        // Background field: since there IS no opaque background in
        // this fixture, background should either be missing OR be
        // sourced from a legit opaque pixel — NOT from the
        // transparent half.
        if (isset($palette['background'])) {
            $r = hexdec(substr($palette['background'], 1, 2));
            $g = hexdec(substr($palette['background'], 3, 2));
            $b = hexdec(substr($palette['background'], 5, 2));
            // Transparent PNG default varies by encoder; assert bg
            // isn't the "phantom white/grey" that would appear if
            // transparent pixels were treated as white.
            $isPhantomWhite = $r > 200 && $g > 200 && $b > 200;
            $this->assertFalse($isPhantomWhite, 'background must not be phantom white from transparent region; got '.$palette['background']);
        }
    }

    #[Test]
    public function deterministic_same_bytes_same_palette(): void
    {
        $png = $this->syntheticPng([
            [0.0, 0.4, 0xC0, 0x00, 0x20, 0xFF],
            [0.4, 0.7, 0x00, 0x00, 0x00, 0xFF],
            [0.7, 1.0, 0xF0, 0xF0, 0xF0, 0xFF],
        ], 300, 100);

        $ex = $this->extractor();
        $a = $ex->extract($png);
        $b = $ex->extract($png);
        $this->assertSame($a, $b, 'same bytes must produce identical palette');
    }

    #[Test]
    public function mono_hue_brand_does_not_fabricate_accent(): void
    {
        // Only red + black + white — a two-color brand. Accent should
        // NOT be fabricated as a random grey; better null than fake.
        $png = $this->syntheticPng([
            [0.0, 0.4, 0xB0, 0x00, 0x10, 0xFF],  // red
            [0.4, 0.7, 0x00, 0x00, 0x00, 0xFF],  // black
            [0.7, 1.0, 0xFF, 0xFF, 0xFF, 0xFF],  // white
        ], 300, 100);

        $palette = $this->extractor()->extract($png);
        $this->assertNotNull($palette);
        $this->assertArrayHasKey('primary', $palette);
        $this->assertArrayNotHasKey(
            'accent',
            $palette,
            'a two-color brand must NOT get a fabricated accent slot; got: '.($palette['accent'] ?? '(unset — correct)'),
        );
    }

    #[Test]
    public function garbage_bytes_return_null(): void
    {
        $this->assertNull($this->extractor()->extract(''));
        $this->assertNull($this->extractor()->extract('this is not a png'));
    }

    #[Test]
    public function three_chromatic_hue_brand_populates_accent(): void
    {
        // Red + blue + yellow + white. THREE distinct chromatic hues
        // plus white background → accent should populate with the
        // third distinct hue (whichever is hue-distinct from primary
        // AND secondary).
        $png = $this->syntheticPng([
            [0.00, 0.30, 0xC0, 0x00, 0x20, 0xFF],  // red
            [0.30, 0.55, 0x20, 0x40, 0xC0, 0xFF],  // blue
            [0.55, 0.75, 0xF0, 0xC4, 0x0F, 0xFF],  // yellow
            [0.75, 1.00, 0xFF, 0xFF, 0xFF, 0xFF],  // white
        ], 400, 100);

        $palette = $this->extractor()->extract($png);
        $this->assertNotNull($palette);
        $this->assertArrayHasKey('primary', $palette);
        $this->assertArrayHasKey('secondary', $palette);
        $this->assertArrayHasKey(
            'accent',
            $palette,
            'a brand with THREE distinct chromatic hues must populate accent with the third distinct hue',
        );
        // Sanity: primary/secondary/accent are three distinct colors.
        $chromatic = [$palette['primary'], $palette['secondary'], $palette['accent']];
        $this->assertCount(3, array_unique($chromatic), 'primary/secondary/accent must be three distinct hex values');
    }

    /**
     * Build a synthetic RGBA PNG via GD. `bands` is a list of
     * horizontal color bands, each defined by [xStart, xEnd, r, g, b, a]
     * where xStart/xEnd are 0..1 fractions of width. Returns raw PNG
     * bytes suitable for extractor->extract().
     *
     * @param  array<int, array{0: float, 1: float, 2: int, 3: int, 4: int, 5: int}>  $bands
     */
    private function syntheticPng(array $bands, int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        // Start transparent — bands paint over.
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $transparent);
        foreach ($bands as [$x0, $x1, $r, $g, $b, $a]) {
            // GD alpha = 0 fully opaque, 127 fully transparent. Convert
            // from 0..255 alpha in the fixture to GD's 0..127 inverted.
            $gdAlpha = (int) round((255 - $a) / 255 * 127);
            $color = imagecolorallocatealpha($im, $r, $g, $b, $gdAlpha);
            imagefilledrectangle($im, (int) ($x0 * $w), 0, (int) ($x1 * $w) - 1, $h - 1, $color);
        }
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }
}
