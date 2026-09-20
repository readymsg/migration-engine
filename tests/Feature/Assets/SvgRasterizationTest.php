<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Contracts\PublicAssetHost;
use App\Services\Assets\AssetPublisher;
use App\Services\Assets\FakePublicAssetHost;
use App\Services\Assets\SvgRasterizer;
use App\Services\ContractEmitter\AssetLedger;
use App\Services\Extract\BrandExtractor;
use App\Services\Extract\LogoPaletteExtractor;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// v2 §9 SVG rasterization end-to-end. Feeds a synthetic two-colour
// SVG (red + grey) through the REAL AssetLedger → AssetPublisher →
// SvgRasterizer (librsvg) → FakePublicAssetHost path — no fake
// rasterizer, no faked bytes.
//
// Pins four invariants:
//   1. The emitted Asset carries `mimeType: image/png` (the SVG is
//      rasterized upstream; raw SVG never reaches the host).
//   2. `width`/`height` have long edge >= 512 (v2 §9 rule).
//   3. `sha256` matches the actual PNG bytes stored by
//      FakePublicAssetHost — proves the hash reports what was
//      published, not what was received.
//   4. BrandExtractor measures the palette from the RASTERIZED PNG
//      bytes and NEVER makes an HTTP call (`Http::assertNothingSent`).
//      This is the closure of v2 §9's SSRF surface.
//
// If librsvg isn't installed on the runner, the whole test file is
// skipped rather than false-failing — the rasterizer path can't be
// meaningfully tested without the binary.
final class SvgRasterizationTest extends TestCase
{
    private const SYNTHETIC_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="#AE292E" width="60" height="100"/><rect fill="#5C5151" x="60" width="40" height="100"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        $binary = (string) config('services.svg_rasterizer.path', '/opt/homebrew/bin/rsvg-convert');
        if (! is_file($binary) || ! is_executable($binary)) {
            $this->markTestSkipped(
                "librsvg's rsvg-convert not found at {$binary}. Install via "
                .'`brew install librsvg` (macOS) or `apt-get install librsvg2-bin` (Ubuntu).'
            );
        }
    }

    #[Test]
    public function svg_rasterises_to_png_with_long_edge_at_least_512(): void
    {
        // Real host so we can inspect the bytes it stored.
        $host = new FakePublicAssetHost;
        $publisher = new AssetPublisher(
            host: $host,
            cacheDir: null,
            svgRasterizer: new SvgRasterizer,
        );
        $ledger = new AssetLedger($publisher);

        // The publisher's URL fetch path is disk-cached — pre-populate
        // the cache for this synthetic URL so no HTTP call fires.
        $sourceUrl = 'https://cdn.example.test/logo.svg';
        $cacheDir = storage_path('app/private/asset-cache');
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        @file_put_contents($cacheDir.'/'.sha1($sourceUrl), self::SYNTHETIC_SVG);

        // Fake HTTP so we can also assert nothing was fetched.
        Http::fake();

        $registration = $ledger->register(
            sourceUrl: $sourceUrl,
            filename: 'logo.svg',
            mimeType: 'image/svg+xml',
            usage: 'logo',
        );

        $this->assertFalse($registration->rejected, 'SVG should have been rasterized and accepted');

        $assets = $ledger->all()->toArray();
        $this->assertCount(1, $assets, 'exactly one asset should have been declared');

        $asset = $assets[0];

        // (1) mimeType is png after rasterization.
        $this->assertSame('image/png', $asset['mimeType']);

        // (2) long edge >= 512.
        $width = $asset['width'] ?? null;
        $height = $asset['height'] ?? null;
        $this->assertIsInt($width, 'width should be measured from the rasterized PNG');
        $this->assertIsInt($height, 'height should be measured from the rasterized PNG');
        $this->assertTrue(
            $width >= 512 || $height >= 512,
            "long edge of {$width}x{$height} PNG must be >= 512 per v2 §9"
        );

        // (3) sha256 matches the bytes FakePublicAssetHost actually holds.
        $storedBytes = $host->get($asset['url']);
        $this->assertNotNull($storedBytes, 'FakePublicAssetHost should hold the published bytes');
        $this->assertSame(hash('sha256', $storedBytes), $asset['sha256']);
        $this->assertSame(strlen($storedBytes), $asset['byteSize']);

        // Filename got .svg → .png flip.
        $this->assertSame('logo.png', $asset['filename']);

        // url origin matches the host's declared origin.
        $this->assertStringStartsWith($host->origin().'/', $asset['url']);

        // No HTTP call fired — bytes came from the disk cache.
        Http::assertNothingSent();
    }

    #[Test]
    public function same_svg_bytes_yield_deterministic_url_and_hash(): void
    {
        $host = new FakePublicAssetHost;
        $publisher = new AssetPublisher(
            host: $host,
            cacheDir: null,
            svgRasterizer: new SvgRasterizer,
        );

        $r1 = $publisher->publishBytes(self::SYNTHETIC_SVG, 'a.svg');
        $r2 = $publisher->publishBytes(self::SYNTHETIC_SVG, 'a.svg');

        $this->assertTrue($r1->isOk());
        $this->assertTrue($r2->isOk());
        $this->assertSame($r1->published->url, $r2->published->url, 'same bytes ⇒ same content-addressed URL');
        $this->assertSame($r1->published->sha256, $r2->published->sha256, 'same bytes ⇒ same sha256');
    }

    #[Test]
    public function brand_extractor_measures_palette_from_rasterized_png_without_http(): void
    {
        // Wire BrandExtractor with a real AssetPublisher + palette
        // extractor (as production does). The publisher's fetch path
        // MUST come from disk cache, so no live HTTP is made.
        $host = new FakePublicAssetHost;
        $publisher = new AssetPublisher(
            host: $host,
            cacheDir: null,
            svgRasterizer: new SvgRasterizer,
        );
        $brand = new BrandExtractor(
            paletteExtractor: new LogoPaletteExtractor,
            assetPublisher: $publisher,
        );

        $logoUrl = 'https://cdn.example.test/brand-logo.svg';
        $cacheDir = storage_path('app/private/asset-cache');
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        @file_put_contents($cacheDir.'/'.sha1($logoUrl), self::SYNTHETIC_SVG);

        Http::fake();

        // Homepage HTML with a header banner_graphic that points at
        // our SVG URL — BrandExtractor's first-attachment regex needs
        // it to look like a sportngin CDN path.
        $svgOnCdnUrl = 'https://cdn2.sportngin.com/attachments/banner_graphic/abcd/logo.svg';
        @file_put_contents($cacheDir.'/'.sha1($svgOnCdnUrl), self::SYNTHETIC_SVG);
        $html = "<html><body><img src=\"{$svgOnCdnUrl}\"></body></html>";

        // AssetUploader is only used for the s3_key hand-off which
        // BrandExtractor still calls; give it a synthetic one.
        $uploader = new class implements \App\Services\Extract\AssetUploader
        {
            public function putFromUrl(string $sourceUrl, string $orgId, string $kind): \App\Data\AssetRef
            {
                return new \App\Data\AssetRef(
                    s3_key: 's3://orgs/'.$orgId.'/'.$kind.'/'.sha1($sourceUrl),
                    mime_type: 'image/svg+xml',
                    source_url: $sourceUrl,
                );
            }

            public function putContent(string $content, string $mimeType, string $orgId, string $kind, string $name): \App\Data\AssetRef
            {
                return new \App\Data\AssetRef(
                    s3_key: 's3://orgs/'.$orgId.'/'.$kind.'/'.$name,
                    mime_type: $mimeType,
                );
            }
        };

        $result = $brand->extract($html, 'ngin-test', $uploader);

        $this->assertNotEmpty($result->palette, 'palette should have been measured from the rasterized PNG');
        // Two dominant colours in the synthetic SVG: red (#AE292E) and
        // grey (#5C5151). Assert both hues surface.
        $hexes = array_values($result->palette);
        $hasRedish = collect($hexes)->contains(fn ($h) => self::isRedish((string) $h));
        $hasGreyish = collect($hexes)->contains(fn ($h) => self::isGreyish((string) $h));
        $this->assertTrue($hasRedish, 'red dominant colour should appear in palette: '.implode(',', $hexes));
        $this->assertTrue($hasGreyish, 'grey dominant colour should appear in palette: '.implode(',', $hexes));

        // Load-bearing: no HTTP calls at all. The palette bytes came
        // from disk cache + rasterizer, not from a fresh Http::get.
        Http::assertNothingSent();
    }

    private static function isRedish(string $hex): bool
    {
        $rgb = self::hexToRgb($hex);
        if ($rgb === null) {
            return false;
        }

        return $rgb[0] > 120 && $rgb[1] < 90 && $rgb[2] < 90;
    }

    private static function isGreyish(string $hex): bool
    {
        $rgb = self::hexToRgb($hex);
        if ($rgb === null) {
            return false;
        }
        $spread = max($rgb) - min($rgb);
        // Neutral-ish AND mid-luminance.
        $lum = ($rgb[0] + $rgb[1] + $rgb[2]) / 3;

        return $spread < 25 && $lum > 40 && $lum < 200;
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function hexToRgb(string $hex): ?array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) !== 6 || preg_match('/^[0-9a-f]{6}$/i', $h) !== 1) {
            return null;
        }

        return [
            (int) hexdec(substr($h, 0, 2)),
            (int) hexdec(substr($h, 2, 2)),
            (int) hexdec(substr($h, 4, 2)),
        ];
    }
}
