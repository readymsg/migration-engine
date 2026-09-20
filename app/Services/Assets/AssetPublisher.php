<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Contracts\PublicAssetHost;
use App\Data\PublishedAsset;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

// Fetch → sniff → rasterize-if-svg → measure → publish. Turns a
// scraped third-party source URL (or raw bytes handed in) into a
// v2-compliant PublishedAsset via the configured PublicAssetHost.
//
// The steps, in order:
//   1. Fetch bytes (or take pre-supplied bytes). Disk-cached under
//      `storage/app/private/asset-cache/<sha1-of-source-url>` so
//      fixture emissions don't re-fetch the same CDN URL every run.
//   2. Byte-sniff mimeType via finfo (v2 §9 verbatim: "verified
//      against what the URL actually serves, not trusted").
//   3. If SVG: skip with `svg_rasterizer_missing` (rasterization
//      deferred to a follow-up slice — see CLAUDE.md Known Gaps).
//   4. Measure width/height via getimagesizefromstring. Missing dims
//      are legit (PDFs).
//   5. Hand bytes to PublicAssetHost::put(), return PublishedAsset
//      plus a `bytes` field for callers that want to feed the
//      bytes directly to another consumer (BrandExtractor →
//      LogoPaletteExtractor) without a second fetch.
//
// FAILURE POSTURE: fetch errors, empty bodies, rasterization failures,
// unknown mimes all return a `PublishResult` with `published === null`
// and a `skip_reason` string. Callers surface `skip_reason` as a
// DiagnosticsSidecar entry — never silently drop.
//
// Non-final on purpose: SyntheticAssetPublisher extends this for the
// network-free test path.
class AssetPublisher
{
    /**
     * Contract Part II "Accepted types" — the only mimes that
     * survive as-is. SVG is skipped at ingest with
     * `svg_rasterizer_missing` (v2 §9 rejects raw SVG as a
     * stored-XSS vector; rasterization is deferred to a follow-up
     * slice).
     *
     * @var array<int, string>
     */
    private const ACCEPTED_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf',
    ];

    public function __construct(
        protected readonly PublicAssetHost $host,
        protected readonly ?string $cacheDir = null,
    ) {}

    /**
     * Publish bytes at $sourceUrl through the host. Returns null
     * `published` + a `skip_reason` string on any failure (fetch,
     * empty body, SVG, unknown mime).
     */
    public function publishFromUrl(string $sourceUrl, string $filename): PublishResult
    {
        $bytes = $this->fetch($sourceUrl);
        if ($bytes === null) {
            return PublishResult::skip("fetch_failed for {$sourceUrl}");
        }

        return $this->publishBytes($bytes, $filename, $sourceUrl);
    }

    /**
     * Publish already-in-hand bytes. Same sniff/measure/publish
     * pipeline as publishFromUrl but without the fetch step.
     */
    public function publishBytes(string $bytes, string $filename, string $provenance = ''): PublishResult
    {
        if ($bytes === '') {
            return PublishResult::skip("empty body from {$provenance}");
        }

        $mime = self::sniffMime($bytes);
        if ($mime === null) {
            return PublishResult::skip("mime_sniff_failed for {$provenance}");
        }
        if ($mime === 'image/svg+xml') {
            // v2 §9 rejects raw SVG as stored-XSS. Rasterization is
            // deferred to a follow-up slice (see CLAUDE.md Known
            // Gaps). Skip visibly so the reviewer sees the drop.
            return PublishResult::skip("svg_rasterizer_missing for {$provenance}");
        }
        if (! in_array($mime, self::ACCEPTED_MIMES, true)) {
            return PublishResult::skip("mime_not_accepted `{$mime}` for {$provenance}");
        }

        try {
            $published = $this->host->put($bytes, $filename, $mime);
        } catch (Throwable $e) {
            return PublishResult::skip("host_put_threw: {$e->getMessage()}");
        }

        return PublishResult::ok($published, $bytes);
    }

    /**
     * Fetch bytes for a source URL. Disk-cached under
     * `storage/app/private/asset-cache/<sha1(url)>` — fixture
     * emission stays reproducible + doesn't hammer the CDN.
     */
    private function fetch(string $sourceUrl): ?string
    {
        $cacheDir = $this->cacheDir ?? storage_path('app/private/asset-cache');
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $cachePath = $cacheDir.'/'.sha1($sourceUrl);
        if (is_file($cachePath)) {
            $cached = file_get_contents($cachePath);
            if ($cached !== false && $cached !== '') {
                return $cached;
            }
        }
        try {
            $response = Http::timeout(15)->get($sourceUrl);
        } catch (Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $bytes = (string) $response->body();
        if ($bytes === '') {
            return null;
        }
        @file_put_contents($cachePath, $bytes);

        return $bytes;
    }

    private static function sniffMime(string $bytes): ?string
    {
        if (! function_exists('finfo_buffer')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        try {
            $mime = finfo_buffer($finfo, $bytes);
        } finally {
            finfo_close($finfo);
        }
        if (! is_string($mime) || $mime === '') {
            return null;
        }

        // finfo reports "image/svg" or "image/svg+xml" depending on
        // libmagic version — normalise so the SVG-skip branch fires
        // reliably.
        if ($mime === 'image/svg') {
            return 'image/svg+xml';
        }

        return $mime;
    }
}

// Publish outcome. `published` is null on skip; `skip_reason`
// carries the diagnostic message. Callers surface skip_reason via
// DiagnosticsSidecar rather than silently dropping.
final class PublishResult
{
    private function __construct(
        public readonly ?PublishedAsset $published,
        public readonly ?string $skip_reason,
        public readonly ?string $bytes,
    ) {}

    public static function ok(PublishedAsset $p, string $bytes): self
    {
        return new self($p, null, $bytes);
    }

    public static function skip(string $reason): self
    {
        return new self(null, $reason, null);
    }

    public function isOk(): bool
    {
        return $this->published !== null;
    }
}
