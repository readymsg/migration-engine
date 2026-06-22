<?php

declare(strict_types=1);

namespace App\Services\Extract;

use App\Data\Brand;

// Brand fallback ladder per BUILD.md: header → og:image → favicon → flag.
// Real SportsEngine signals (recon'd across 6 live sites):
//   header  := first `attachments/banner_graphic/.../<file>` or, if absent,
//              `attachments/logo_graphic/.../<file>` (the in-header logo)
//   og:image := <meta property="og:image" content="…">
//   favicon  := `attachments/favicon_graphic/...` or `<link rel="shortcut icon">`
// The chosen logo is persisted to S3 via the uploader so Brand only ever
// carries the s3 ref — never bytes, never a third-party URL.
final class BrandExtractor
{
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
                    palette: [],     // TODO: extract from theme.css / inline <style> if we ever need it
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
