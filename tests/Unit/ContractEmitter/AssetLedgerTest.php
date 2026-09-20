<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\SiteImport\Asset;
use App\Services\ContractEmitter\AssetLedger;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

// Pins the asset ledger against v2 §7 "Assets" + §9 "Asset hosting"
// rules. Load-bearing v2 properties:
//   1. Every declared asset has `url` on the allow-listed https host
//      (Fake for tests: https://assets.example.test/…).
//   2. `sourceUrl` is provenance-only (kept in the DTO for
//      traceability but never fetched by the ingest).
//   3. `sha256`, `byteSize`, `width`, `height` populate from the
//      actual bytes handed to the host — verified below.
//   4. SVG is skipped (rasterisation deferred; see Known Gap in
//      CLAUDE.md).
//   5. Dedupe by sourceUrl — one publish per distinct source.
//
// Tests instantiate `new AssetLedger` (no arg) so the ledger falls
// back to a SyntheticAssetPublisher (network-free, mime derived from
// the filename extension).
final class AssetLedgerTest extends TestCase
{
    // ─── happy path ──────────────────────────────────────────────────────

    #[Test]
    public function accepts_jpeg_and_returns_tl_asset_token(): void
    {
        $ledger = new AssetLedger;
        $result = $ledger->register(
            sourceUrl: 'https://cdn2.sportngin.com/attachments/photo/64f2/rink.jpg',
            filename: 'rink.jpg',
            mimeType: 'image/jpeg',
            alt: 'Community rink',
            usage: 'hero',
        );
        $this->assertFalse($result->rejected);
        $this->assertNotNull($result->ref);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,64}$/', $result->ref);

        // Deterministic: same URL → same ref.
        $again = $ledger->register(
            sourceUrl: 'https://cdn2.sportngin.com/attachments/photo/64f2/rink.jpg',
            filename: 'rink.jpg',
            mimeType: 'image/jpeg',
        );
        $this->assertSame($result->ref, $again->ref);
    }

    #[Test]
    public function published_asset_carries_v2_url_sha256_and_bytesize(): void
    {
        $ledger = new AssetLedger;
        $ledger->register('https://x.com/hero.png', 'hero.png', 'image/png', usage: 'hero');
        /** @var Asset $asset */
        $asset = $ledger->all()->items()[0];

        // v2 required `url` on the allow-listed host.
        $this->assertStringStartsWith('https://assets.example.test/', $asset->url);
        // v2 sourceUrl is provenance — populated but distinct from url.
        $this->assertSame('https://x.com/hero.png', $asset->sourceUrl);
        $this->assertNotSame($asset->sourceUrl, $asset->url);
        // Measurement fields populated from the synthetic PNG bytes.
        $this->assertIsString($asset->sha256);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $asset->sha256);
        $this->assertGreaterThan(0, $asset->byteSize);
        // Synthetic PNG is 1×1 so width/height are known.
        $this->assertSame(1, $asset->width);
        $this->assertSame(1, $asset->height);
    }

    #[Test]
    public function usage_hint_prefixes_the_ref(): void
    {
        $ledger = new AssetLedger;
        $withUsage = $ledger->register('https://x.com/a.png', 'a.png', 'image/png', usage: 'logo');
        $noUsage = $ledger->register('https://x.com/b.png', 'b.png', 'image/png');
        $this->assertStringStartsWith('logo-', (string) $withUsage->ref);
        $this->assertStringStartsWith('asset-', (string) $noUsage->ref);
    }

    #[Test]
    public function all_accepted_mime_types_are_accepted(): void
    {
        $ledger = new AssetLedger;
        // Each row: filename (drives synth mime) + declared mime.
        $rows = [
            ['a.jpg', 'image/jpeg'],
            ['b.png', 'image/png'],
            ['c.webp', 'image/webp'],
            ['d.gif', 'image/gif'],
            ['e.pdf', 'application/pdf'],
        ];
        foreach ($rows as $i => [$name, $mime]) {
            $r = $ledger->register("https://x.com/{$name}", $name, $mime);
            $this->assertFalse($r->rejected, "{$mime} must be accepted");
        }
    }

    #[Test]
    public function dedupes_by_source_url(): void
    {
        $ledger = new AssetLedger;
        $a = $ledger->register('https://x.com/logo.png', 'logo.png', 'image/png', usage: 'logo');
        $b = $ledger->register('https://x.com/logo.png', 'logo.png', 'image/png', usage: 'hero');
        $this->assertSame($a->ref, $b->ref);
        $this->assertSame(1, $ledger->count());
    }

    #[Test]
    public function token_for_returns_full_prefixed_token(): void
    {
        $ledger = new AssetLedger;
        $token = $ledger->tokenFor(
            sourceUrl: 'https://x.com/hero.jpg',
            filename: 'hero.jpg',
            mimeType: 'image/jpeg',
            usage: 'hero',
        );
        $this->assertNotNull($token);
        $this->assertStringStartsWith('tl-asset:', $token);
        $ref = substr($token, strlen('tl-asset:'));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,64}$/', $ref);
    }

    #[Test]
    public function all_returns_data_collection_in_registration_order(): void
    {
        $ledger = new AssetLedger;
        $ledger->register('https://x.com/1.png', 'one.png', 'image/png');
        $ledger->register('https://x.com/2.jpg', 'two.jpg', 'image/jpeg');
        $ledger->register('https://x.com/3.pdf', 'three.pdf', 'application/pdf');

        $all = $ledger->all();
        $this->assertCount(3, $all);
        /** @var array<int, Asset> $items */
        $items = $all->items();
        $this->assertSame('one.png', $items[0]->filename);
        $this->assertSame('two.jpg', $items[1]->filename);
        $this->assertSame('three.pdf', $items[2]->filename);
    }

    #[Test]
    public function alt_and_usage_are_optional_and_omitted_when_unset(): void
    {
        $ledger = new AssetLedger;
        $ledger->register('https://x.com/plain.png', 'plain.png', 'image/png');
        /** @var Asset $asset */
        $asset = $ledger->all()->items()[0];
        $this->assertInstanceOf(Optional::class, $asset->alt);
        $this->assertInstanceOf(Optional::class, $asset->usage);
    }

    #[Test]
    public function alt_and_usage_are_carried_through_when_set(): void
    {
        $ledger = new AssetLedger;
        $ledger->register(
            sourceUrl: 'https://x.com/hero.jpg',
            filename: 'hero.jpg',
            mimeType: 'image/jpeg',
            alt: 'Players on ice',
            usage: 'hero',
        );
        /** @var Asset $asset */
        $asset = $ledger->all()->items()[0];
        $this->assertSame('Players on ice', $asset->alt);
        $this->assertSame('hero', $asset->usage);
    }

    // ─── SVG rasterization — v2 §9 (slice B) ─────────────────────────────

    #[Test]
    public function svg_is_rasterised_to_png_and_accepted_when_binary_present(): void
    {
        // Contract v2 §9: raw SVG rejected as stored-XSS vector, but
        // the AssetPublisher rasterises to PNG ≥ 512 px long edge
        // BEFORE handing to the host. SyntheticAssetPublisher
        // exercises the real librsvg path — this test is skipped only
        // when the binary isn't available (CI without librsvg).
        $binary = (string) config('services.svg_rasterizer.path', '/opt/homebrew/bin/rsvg-convert');
        if (! is_file($binary) || ! is_executable($binary)) {
            $this->markTestSkipped("librsvg's rsvg-convert not found at {$binary} — SVG rasterization path can't be tested without it.");
        }

        $ledger = new AssetLedger;
        $r = $ledger->register('https://x.com/logo.svg', 'logo.svg', 'image/svg+xml', usage: 'logo');
        $this->assertFalse($r->rejected, 'SVG should be rasterised, not rejected');
        $this->assertSame(1, $ledger->count());

        /** @var Asset $asset */
        $asset = $ledger->all()->items()[0];
        $this->assertSame('image/png', $asset->mimeType, 'SVG bytes should be rasterised to PNG');
        $this->assertSame('logo.png', $asset->filename, 'filename should reflect the rasterised PNG');
        $this->assertIsInt($asset->width);
        $this->assertIsInt($asset->height);
        $this->assertTrue(
            $asset->width >= 512 || $asset->height >= 512,
            'rasterised PNG long edge must be >= 512 px per v2 §9'
        );
        // No skip diagnostic — SVG succeeded.
        $this->assertCount(0, $ledger->skipDiagnostics());
    }

    #[Test]
    public function svg_falls_back_to_unavailable_diagnostic_when_binary_missing(): void
    {
        // When the binary IS missing on a deploy (Forge without
        // librsvg2-bin installed), the publisher catches
        // SvgRasterizerUnavailable and skips with a distinct
        // `svg_rasterizer_unavailable` diagnostic — visible in sidecar,
        // never a hard fail. Distinct from `svg_rasterizer_missing`
        // (infra-not-wired) so a reviewer can tell an infra problem
        // from a wiring problem.
        $cacheDir = sys_get_temp_dir().'/ml-test-svg-'.uniqid();
        @mkdir($cacheDir, 0775, true);
        $sourceUrl = 'https://x.com/logo.svg';
        @file_put_contents(
            $cacheDir.'/'.sha1($sourceUrl),
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect fill="#000" width="10" height="10"/></svg>'
        );
        $ledger = new AssetLedger(
            new \App\Services\Assets\AssetPublisher(
                host: new \App\Services\Assets\FakePublicAssetHost,
                cacheDir: $cacheDir,
                svgRasterizer: new \App\Services\Assets\SvgRasterizer('/does/not/exist/rsvg-convert'),
            )
        );

        $r = $ledger->register($sourceUrl, 'logo.svg', 'image/svg+xml');
        $this->assertTrue($r->rejected);
        $this->assertStringContainsString('svg_rasterizer_unavailable', (string) $r->reason);
        $diagnostics = $ledger->skipDiagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertSame('svg_rasterizer_unavailable', $diagnostics[0]->code);
    }

    // ─── other rejection / skip cases — each becomes a sidecar diagnostic ─

    #[Test]
    public function scheme_less_source_url_is_rejected(): void
    {
        $ledger = new AssetLedger;
        $r = $ledger->register('//cdn.example.com/x.png', 'x.png', 'image/png');
        $this->assertTrue($r->rejected);
        $this->assertStringContainsString('absolute', (string) $r->reason);
    }

    #[Test]
    public function s3_scheme_source_url_is_rejected(): void
    {
        // Load-bearing: our OWN s3:// keys must NOT enter the ledger.
        // Under v2 the URL that ships is `url` (on the public host);
        // sourceUrl is provenance. An s3:// value has no place in
        // either field.
        $ledger = new AssetLedger;
        $r = $ledger->register('s3://engine-bucket/orgs/x/logos/abc.png', 'abc.png', 'image/png');
        $this->assertTrue($r->rejected);
    }

    #[Test]
    public function empty_source_url_is_rejected(): void
    {
        $ledger = new AssetLedger;
        $r = $ledger->register('', 'f.png', 'image/png');
        $this->assertTrue($r->rejected);
    }

    #[Test]
    public function token_for_returns_null_on_skip(): void
    {
        // Skip must come from an ACTUAL skip case (empty sourceUrl —
        // not SVG, which now succeeds through rasterization). The
        // property we're pinning: skipped ⇒ tokenFor returns null.
        $ledger = new AssetLedger;
        $token = $ledger->tokenFor('', 'x.png', 'image/png');
        $this->assertNull($token, 'token must be null when the asset is skipped');
    }

    // ─── ref grammar edge cases ──────────────────────────────────────────

    #[Test]
    public function invalid_usage_hint_falls_back_to_asset_prefix(): void
    {
        $ledger = new AssetLedger;
        $r = $ledger->register('https://x.com/a.png', 'a.png', 'image/png', usage: 'Hero Image!');
        $this->assertFalse($r->rejected);
        $this->assertNotNull($r->ref);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,64}$/', $r->ref);
        $this->assertStringStartsWith('asset-', $r->ref);
    }
}
