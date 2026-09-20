<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Services\Assets\AssetPublisher;
use App\Services\Assets\FakePublicAssetHost;
use App\Services\Assets\SvgRasterizer;
use App\Services\ContractEmitter\AssetLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Pins the "infra-wired-but-binary-absent" branch. When librsvg is
// missing on a deploy (e.g. Forge without `apt-get install
// librsvg2-bin`), the SvgRasterizer throws SvgRasterizerUnavailable
// on the first call, AssetPublisher catches it, and the asset is
// DROPPED VISIBLY with a distinct `svg_rasterizer_unavailable`
// sidecar diagnostic. Never a hard fail; never a silent skip; never
// a fall-through that declares the raw SVG.
//
// Distinct from `svg_rasterizer_missing` (which fires when no
// rasterizer is injected at all — infra-not-wired) so a reviewer
// can tell a deploy problem from a configuration problem.
//
// Does NOT depend on librsvg being installed: fakes the binary path
// to `/does/not/exist/rsvg-convert` so the same failure mode
// reproduces on any CI.
final class SvgRasterizerUnavailableTest extends TestCase
{
    private const SYNTHETIC_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect fill="#000" width="10" height="10"/></svg>';

    #[Test]
    public function svg_drops_visibly_with_unavailable_diagnostic_when_binary_absent(): void
    {
        $sourceUrl = 'https://cdn.example.test/logo.svg';

        // Pre-populate the disk cache so the publisher's fetch step
        // returns bytes without HTTP — isolates the failure to the
        // rasterizer step.
        $cacheDir = sys_get_temp_dir().'/ml-test-svg-'.uniqid();
        @mkdir($cacheDir, 0775, true);
        @file_put_contents($cacheDir.'/'.sha1($sourceUrl), self::SYNTHETIC_SVG);

        $publisher = new AssetPublisher(
            host: new FakePublicAssetHost,
            cacheDir: $cacheDir,
            svgRasterizer: new SvgRasterizer('/does/not/exist/rsvg-convert'),
        );
        $ledger = new AssetLedger($publisher);

        // (1) tokenFor returns null — asset is NOT emitted.
        $token = $ledger->tokenFor($sourceUrl, 'logo.svg', 'image/svg+xml', usage: 'logo');
        $this->assertNull($token, 'token must be null when SVG is dropped');
        $this->assertCount(0, $ledger->all(), 'no asset should be emitted');

        // (2) A single sidecar diagnostic with code EXACTLY
        //     `svg_rasterizer_unavailable`.
        $diagnostics = $ledger->skipDiagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertSame('svg_rasterizer_unavailable', $diagnostics[0]->code);
        $this->assertSame('warning', $diagnostics[0]->severity);

        // (3) The diagnostic message mentions the source URL so a
        //     reviewer can find the specific asset that dropped.
        $this->assertStringContainsString(
            $sourceUrl,
            (string) $diagnostics[0]->sourceUrl,
            'sidecar diagnostic should carry the source URL for traceability'
        );
    }

    #[Test]
    public function unavailable_is_distinct_from_missing(): void
    {
        // Both branches drop visibly, but with distinct codes so a
        // reviewer can tell "no rasterizer injected" from "rasterizer
        // injected but binary absent".
        $noRasterizerLedger = new AssetLedger(
            new AssetPublisher(host: new FakePublicAssetHost, cacheDir: null)
        );
        // publishBytes path so the null-rasterizer branch fires
        // deterministically without depending on cache setup.
        $result = $noRasterizerLedger->register(
            'https://cdn.example.test/a.svg',
            'a.svg',
            'image/svg+xml',
        );
        // fetch path with no cache returns 'fetch_failed' — swap to
        // a cache-pre-populated ledger so the SVG branch is what
        // actually runs.
        $cacheDir = sys_get_temp_dir().'/ml-missing-'.uniqid();
        @mkdir($cacheDir, 0775, true);
        $url = 'https://cdn.example.test/b.svg';
        @file_put_contents($cacheDir.'/'.sha1($url), self::SYNTHETIC_SVG);
        $missingLedger = new AssetLedger(
            new AssetPublisher(host: new FakePublicAssetHost, cacheDir: $cacheDir)
        );
        $missingLedger->register($url, 'b.svg', 'image/svg+xml');

        $missingCode = $missingLedger->skipDiagnostics()[0]->code;
        $this->assertSame('svg_rasterizer_missing', $missingCode);

        // Unavailable branch (from the first test's setup).
        $unavailUrl = 'https://cdn.example.test/c.svg';
        $unavailCache = sys_get_temp_dir().'/ml-unavail-'.uniqid();
        @mkdir($unavailCache, 0775, true);
        @file_put_contents($unavailCache.'/'.sha1($unavailUrl), self::SYNTHETIC_SVG);
        $unavailLedger = new AssetLedger(
            new AssetPublisher(
                host: new FakePublicAssetHost,
                cacheDir: $unavailCache,
                svgRasterizer: new SvgRasterizer('/does/not/exist/rsvg-convert'),
            )
        );
        $unavailLedger->register($unavailUrl, 'c.svg', 'image/svg+xml');

        $unavailCode = $unavailLedger->skipDiagnostics()[0]->code;
        $this->assertSame('svg_rasterizer_unavailable', $unavailCode);

        $this->assertNotSame($missingCode, $unavailCode, 'the two codes must be distinct');
    }
}
