<?php

declare(strict_types=1);

namespace App\Services\Extract;

use App\Data\Brand;
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
// PALETTE MEASUREMENT: when the ladder finds a logo AND a LogoPaletteExtractor
// is available (constructor arg), we do a second HTTP fetch of the same URL
// to feed the extractor. This resolves the historical "TODO: extract from
// theme.css / inline <style>" comment via the image path instead — a
// deterministic quantised histogram of the actual logo pixels, which returns
// the real club identity (e.g. tbirdhoops = red + black + white). Failure to
// fetch or extract leaves `Brand.palette` empty (current behaviour) and the
// preview falls through to the LLM-inferred palette.
final class BrandExtractor
{
    public function __construct(
        private readonly ?LogoPaletteExtractor $paletteExtractor = null,
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
                    voice_hint: null, // TODO: nothing on the homepage is a reliable voice signal
                    palette_error: $paletteError,
                );
            }
        }

        return new Brand(
            logo_source: 'flag',
            logo_asset_ref: null,
            palette: [],
            voice_hint: null,
            // No logo URL to measure — this is a legitimate absence,
            // NOT a measurement failure. Leave palette_error null; the
            // downstream diagnostic will surface as
            // 'palette_primary_missing' (no source at all) rather than
            // 'palette_primary_from_llm_guess' (measured failed).
        );
    }

    /**
     * Returns [palette, error]. `error` is null on success or when no
     * palette extractor is configured to run.
     *
     * @return array{0: array<string, string>, 1: ?string}
     */
    private function measurePalette(string $logoUrl): array
    {
        if ($this->paletteExtractor === null) {
            return [[], 'no_palette_extractor'];
        }
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
        $palette = $this->paletteExtractor->extract($bytes) ?? [];
        if ($palette === []) {
            return [[], 'palette_extraction_empty'];
        }

        return [$palette, null];
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
