<?php

declare(strict_types=1);

namespace App\Services\Extract;

use App\Data\AssetRef;

// Scans a scraped page body (markdown + html + images list) for asset URLs
// hosted on SE infrastructure and re-hosts them to S3. The rebuilt site
// then has zero live SportsEngine dependency — GENERATE can rewrite refs
// to the S3 keys at content-fill time (a later slice; this slice only
// captures them).
//
// Host matching (path-agnostic — the SE-platform-link rule already filters
// nav-link intent at the PLAN layer):
//   - *.sportngin.com   (cdn1-4, app-assets1-3, etc.)
//   - assets.ngin.com   (the one ngin.com host SE actively serves assets from)
//
// We do NOT blanket-match other ngin.com subdomains — they're rare and we'd
// rather under-rehost than slurp unrelated hosts.
final class SeCdnRehoster
{
    private const ASSETS_KIND = 'content_assets';

    public function __construct(
        private readonly AssetUploader $uploader,
    ) {}

    /**
     * Re-host every UNIQUE SE-CDN asset URL referenced from the page. Per-asset
     * failures are swallowed (the page body still landed; missing inline
     * images are a softer signal than missing body content) — but we DO
     * report the count of URLs found vs. successfully re-hosted so a page
     * that silently lost half its images isn't invisible. The extractor
     * sums these across pages and surfaces a Manifest counter + flag.
     *
     * @return array{refs: array<int, AssetRef>, found: int, rehosted: int}
     */
    public function rehost(ScrapedPage $scrape, string $orgId): array
    {
        $urls = $this->collectSeCdnUrls($scrape);

        $refs = [];
        foreach ($urls as $url) {
            try {
                $refs[] = $this->uploader->putFromUrl($url, $orgId, self::ASSETS_KIND);
            } catch (\Throwable) {
                continue;
            }
        }

        return [
            'refs' => $refs,
            'found' => count($urls),
            'rehosted' => count($refs),
        ];
    }

    /**
     * @return array<int, string>  unique SE-CDN URLs across markdown + html + images
     */
    private function collectSeCdnUrls(ScrapedPage $scrape): array
    {
        /** @var array<string, true> $seen */
        $seen = [];

        // Firecrawl's images list (when requested via formats=['images']).
        foreach ($scrape->image_urls as $url) {
            if ($this->isSeCdn($url)) {
                $seen[$this->canonical($url)] = true;
            }
        }

        // Pull every http(s) URL out of markdown + html and filter by host.
        $combined = $scrape->markdown."\n".$scrape->html;
        if (preg_match_all('#https?://[^\s"\'<>()\[\]]+#i', $combined, $matches) > 0) {
            foreach ($matches[0] as $url) {
                $clean = rtrim($url, '.,;:!?');
                if ($this->isSeCdn($clean)) {
                    $seen[$this->canonical($clean)] = true;
                }
            }
        }

        return array_keys($seen);
    }

    private function isSeCdn(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);

        if ($host === 'sportngin.com' || str_ends_with($host, '.sportngin.com')) {
            return true;
        }
        if ($host === 'assets.ngin.com') {
            return true;
        }

        return false;
    }

    /**
     * Strip the query string + fragment so two requests for the same asset
     * with different cache-busters don't get re-hosted twice.
     */
    private function canonical(string $url): string
    {
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        $q = strpos($url, '?');
        if ($q !== false) {
            $url = substr($url, 0, $q);
        }

        return $url;
    }
}
