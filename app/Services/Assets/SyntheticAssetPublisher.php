<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Contracts\PublicAssetHost;
use App\Data\PublishedAsset;
use Throwable;

// Network-free publisher used as the default for unit tests. Trusts
// the caller's declared mime (v2 §9 wants byte-sniffed mime for
// production, but a test asserting "the ledger accepts JPEG" needs a
// deterministic path that doesn't require fetching a real JPEG).
// Synthesises deterministic placeholder bytes per mime so the
// FakePublicAssetHost still produces a valid URL + sha256 + byteSize
// + measurable width/height (for the tiny PNG we generate).
//
// SVG is skipped at publish time with `svg_rasterizer_missing` —
// rasterization is deferred to a follow-up slice (see CLAUDE.md
// Known Gaps).
//
// The production fixture-emission path uses `AssetPublisher` (the
// fetching variant) instead — the container binds `AssetPublisher`
// as a singleton, and `ContractPayloadEmitter` receives it via DI.
// `AssetLedger` falls back to `SyntheticAssetPublisher` only when
// constructed WITHOUT an explicit publisher — i.e., in tests.
final class SyntheticAssetPublisher extends AssetPublisher
{
    public function __construct(PublicAssetHost $host)
    {
        parent::__construct($host, null);
    }

    public static function default(): self
    {
        return new self(new FakePublicAssetHost);
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
            return PublishResult::skip("svg_rasterizer_missing for {$sourceUrl}");
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
            default => str_repeat("\0", 8),
        };
    }
}
