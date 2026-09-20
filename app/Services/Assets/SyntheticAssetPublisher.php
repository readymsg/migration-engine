<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Contracts\PublicAssetHost;
use App\Data\PublishedAsset;
use App\Services\Assets\Exceptions\SvgRasterizationFailed;
use App\Services\Assets\Exceptions\SvgRasterizerUnavailable;
use Throwable;

// Network-free publisher used as the default for unit tests. Trusts
// the caller's declared mime (v2 §9 wants byte-sniffed mime for
// production, but a test asserting "the ledger accepts JPEG" needs a
// deterministic path that doesn't require fetching a real JPEG).
// Synthesises deterministic placeholder bytes per mime so the
// FakePublicAssetHost still produces a valid URL + sha256 + byteSize
// + measurable width/height (for the tiny PNG we generate).
//
// SVG is DIFFERENT: unit tests actually want to exercise the real
// librsvg rasterization path (see SvgRasterizationTest). So SVG bytes
// synthesised here are a real two-colour SVG string and the rasterizer
// runs against them. When librsvg is missing (CI without the binary),
// the SVG path degrades to `svg_rasterizer_missing` — same as prod.
//
// The production fixture-emission path uses `AssetPublisher` (the
// fetching variant) instead — the container binds `AssetPublisher`
// as a singleton, and `ContractPayloadEmitter` receives it via DI.
// `AssetLedger` falls back to `SyntheticAssetPublisher` only when
// constructed WITHOUT an explicit publisher — i.e., in tests.
final class SyntheticAssetPublisher extends AssetPublisher
{
    public function __construct(PublicAssetHost $host, ?SvgRasterizer $svgRasterizer = null)
    {
        parent::__construct($host, null, $svgRasterizer);
    }

    public static function default(): self
    {
        return new self(new FakePublicAssetHost, new SvgRasterizer);
    }

    public function publishFromUrl(string $sourceUrl, string $filename): PublishResult
    {
        // Guess mime from the filename extension so the ledger's
        // accept/reject decision matches what the caller declared.
        $mime = self::mimeFromFilename($filename) ?? self::mimeFromFilename($sourceUrl);
        if ($mime === null) {
            return PublishResult::skip("synthetic_mime_unknown for {$filename}");
        }
        if ($mime === 'image/svg+xml') {
            // Route real synthetic SVG bytes through the rasterizer
            // so the test path exercises what production does.
            return $this->publishBytes(self::syntheticBytes('image/svg+xml'), $filename, $sourceUrl);
        }
        $bytes = self::syntheticBytes($mime);

        try {
            $published = $this->host->put($bytes, $filename, $mime);
        } catch (Throwable $e) {
            return PublishResult::skip("host_put_threw: {$e->getMessage()}");
        }

        return PublishResult::ok($published, $bytes);
    }

    private static function mimeFromFilename(string $name): ?string
    {
        $path = parse_url($name, PHP_URL_PATH);
        $target = is_string($path) ? $path : $name;
        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'svg' => 'image/svg+xml',
            default => null,
        };
    }

    private static function syntheticBytes(string $mime): string
    {
        return match ($mime) {
            // 1x1 red pixel PNG — minimum valid PNG with real dims
            // (so FakePublicAssetHost can report width/height=1).
            'image/png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII='),
            // 1x1 JPEG (grey).
            'image/jpeg' => base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='),
            // 1x1 WEBP.
            'image/webp' => base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA=='),
            // Minimal GIF.
            'image/gif' => base64_decode('R0lGODlhAQABAIAAAAUEBAAAACwAAAAAAQABAAACAkQBADs='),
            // Minimal single-page PDF (invalid rendering but valid mime signature).
            'application/pdf' => "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\nxref\n0 1\n0000000000 65535 f\ntrailer<</Root 1 0 R>>\n%%EOF",
            // Two-colour synthetic logo (red left 60%, grey right 40%).
            // Real SVG bytes so the rasterizer exercises the whole path.
            'image/svg+xml' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="#AE292E" width="60" height="100"/><rect fill="#5C5151" x="60" width="40" height="100"/></svg>',
            default => str_repeat("\0", 8),
        };
    }
}
