<?php

declare(strict_types=1);

namespace App\Services\Extract;

use App\Data\Brand;
use App\Services\Assets\AssetPublisher;
use Illuminate\Support\Facades\Http;
use Throwable;

// Brand fallback ladder per BUILD.md: header → og:image → favicon → flag.
// Real SportsEngine signals (recon'd across 6 live sites):
//   header  := first `attachments/banner_graphic/.../<file>` or, if absent,
//              `attachments/logo_graphic/.../<file>` (the in-header logo)
//   og:image := <meta property="og:image" content="…">
//   favicon  := `attachments/favicon_graphic/...` or `<link rel="shortcut icon">`
// The chosen logo is persisted to S3 via the uploader so Brand only ever
// carries the s3 ref — never bytes, never a third-party URL.
//
// PALETTE MEASUREMENT (v2 §9 SSRF-closure):
// When an AssetPublisher is bound (constructor arg), the palette bytes
// come from a SINGLE fetch through it — the same fetch used to publish
// the rehosted asset. No second `Http::get()` against the live CDN.
// This closes the SSRF-surface v2 §9 flagged:
//   "Fetching scraped URLs directly means making server-side requests
//    to arbitrary attacker-influenceable hosts — the whole SSRF surface".
// When no AssetPublisher is bound (legacy path), the second fetch
// remains for backward compat — see the fallback in `measurePalette()`.
final class BrandExtractor
{
    public function __construct(
        private readonly ?LogoPaletteExtractor $paletteExtractor = null,
        private readonly ?AssetPublisher $assetPublisher = null,
    ) {}

    public function extract(string $homepageHtml, string $orgId, AssetUploader $uploader): Brand
    {
        $candidates = [
            'header' => $this->firstAttachment($homepageHtml, 'banner_graphic')
                ?? $this->firstAttachment($homepageHtml, 'logo_graphic'),
            'og_image' => $this->firstOgImage($homepageHtml),
            'favicon' => $this->firstAttachment($homepageHtml, 'favicon_graphic')
                ?? $this->firstLinkIcon($homepageHtml),
        ];

        foreach ($candidates as $source => $url) {
            if (is_string($url) && $url !== '') {
                $asset = $uploader->putFromUrl($url, $orgId, 'logos');
                [$palette, $paletteError] = $this->measurePalette($url);

                return new Brand(
                    logo_source: $source,
                    logo_asset_ref: $asset->s3_key,
                    // Preserve the original CDN URL so the preview
                    // asset resolver can fall back to fetching it
                    // when the rehosted s3_key isn't reachable
                    // (e.g. offline fixture path where the sha1-
                    // named file doesn't exist on disk).
                    logo_source_url: $url,
                    // Measured palette from the logo's actual pixels
                    // (deterministic quantised histogram). Empty on
                    // fetch/decode failure — SiteSettingsEmitter reads
                    // palette_error to produce a LOUD fallback
                    // diagnostic instead of silently falling through
                    // to GlobalStyleBrief.palette.
                    palette: $palette,
                    voice_hint: null,
                    palette_error: $paletteError,
                );
            }
        }

        return new Brand(
            logo_source: 'flag',
            logo_asset_ref: null,
            palette: [],
            voice_hint: null,
        );
    }

    /**
     * Returns [palette, error]. `error` is null on success or when no
     * palette extractor is configured to run.
     *
     * Fetch path (v2 posture): when an AssetPublisher is bound, we
     * fetch bytes through it (disk-cached, one fetch per unique URL)
     * and feed those bytes to the palette extractor. No live
     * `Http::get()` against the source URL. When no publisher is
     * bound, the legacy path is kept for backward compat — but a
     * Known Gap in CLAUDE.md tracks eliminating it.
     *
     * @return array{0: array<string, string>, 1: ?string}
     */
    private function measurePalette(string $logoUrl): array
    {
        if ($this->paletteExtractor === null) {
            return [[], 'no_palette_extractor'];
        }

        $bytes = null;
        if ($this->assetPublisher !== null) {
            $filename = self::filenameFromUrl($logoUrl);
            $publishResult = $this->assetPublisher->publishFromUrl($logoUrl, $filename);
            if ($publishResult->isOk() && $publishResult->bytes !== null) {
                $bytes = $publishResult->bytes;
            } else {
                return [[], 'logo_publish_failed: '.($publishResult->skip_reason ?? 'unknown')];
            }
        } else {
            // Legacy fallback — the second HTTP fetch v2 §9 wants gone.
            // Kept for INGEST paths not yet migrated to AssetPublisher.
            try {
                $response = Http::timeout(10)->get($logoUrl);
            } catch (Throwable $e) {
                return [[], 'logo_fetch_failed: '.$e->getMessage()];
            }
            if (! $response->successful()) {
                return [[], 'logo_fetch_failed: HTTP '.$response->status()];
            }
            $bytes = (string) $response->body();
            if ($bytes === '') {
                return [[], 'logo_body_empty'];
            }
        }

        $palette = $this->paletteExtractor->extract($bytes) ?? [];
        if ($palette === []) {
            return [[], 'palette_extraction_empty'];
        }

        return [$palette, null];
    }

    private static function filenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return 'logo';
        }
        $name = basename($path);

        return $name !== '' ? $name : 'logo';
    }

    private function firstAttachment(string $html, string $kind): ?string
    {
        // Matches https://cdn[1-4].sportngin.com/attachments/<kind>/.../<file>.<ext>
        // Char class deliberately excludes ) and ; so CSS `url(...)` and `;`
        // boundaries don't get swept into the match.
        $pattern = '#https?://[a-z0-9.\-]+\.sportngin\.com/attachments/'
            .preg_quote($kind, '#')
            .'/[A-Za-z0-9_/.\-]+#i';

        return preg_match($pattern, $html, $m) === 1 ? $m[0] : null;
    }

    private function firstOgImage(string $html): ?string
    {
        if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function firstLinkIcon(string $html): ?string
    {
        if (preg_match('#<link[^>]+rel=["\'](?:shortcut\s+icon|icon)["\'][^>]+href=["\']([^"\']+)["\']#i', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
