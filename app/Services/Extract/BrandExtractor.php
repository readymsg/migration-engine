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
                    // fetch/decode failure — caller falls through to
                    // the LLM-inferred GlobalStyleBrief.palette.
                    palette: $this->measurePalette($url),
                    voice_hint: null, // TODO: nothing on the homepage is a reliable voice signal
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
     * @return array<string, string>  0..5 palette tokens (primary/secondary/accent/background/text). Empty on any failure.
     */
    private function measurePalette(string $logoUrl): array
    {
        if ($this->paletteExtractor === null) {
            return [];
        }
        try {
            $response = Http::timeout(10)->get($logoUrl);
        } catch (Throwable) {
            return [];
        }
        if (! $response->successful()) {
            return [];
        }
        $bytes = (string) $response->body();
        if ($bytes === '') {
            return [];
        }

        return $this->paletteExtractor->extract($bytes) ?? [];
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
