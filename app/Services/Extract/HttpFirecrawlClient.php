<?php

declare(strict_types=1);

namespace App\Services\Extract;

use Illuminate\Support\Facades\Http;
use RuntimeException;

// Real Firecrawl v2 client. Endpoint shape verified against the live docs
// at https://docs.firecrawl.dev/api-reference/endpoint/scrape on
// 2026-06-23: POST /v2/scrape with bearer auth, synchronous response of
// shape { success, data: { markdown, html, images?, metadata: {title,
// sourceURL, statusCode, contentType, ... } } }.
//
// Errors are surfaced two ways:
//   - transport / auth / 5xx → throws (extractor catches → failure entry).
//   - 2xx with success=false → returns null (extractor records failure).
//   - 2xx with success=true → returns ScrapedPage.
//
// Network calls are gated behind the FirecrawlClient interface so tests
// inject FakeFirecrawlClient and never reach the wire.
final class HttpFirecrawlClient implements FirecrawlClient
{
    /** Formats we ask Firecrawl to return. Order matches its docs. */
    private const FORMATS = ['markdown', 'html', 'images'];

    /** Per-request timeout (ms). Firecrawl default is 60s; bump a little for slow SE pages. */
    private const TIMEOUT_MS = 90000;

    // SE itasca theme chrome that Firecrawl's onlyMainContent can't detect:
    // the chrome is plain <div>s without <nav>/<header> semantics, so Firecrawl
    // misclassifies it as content. We name each id we ARE excluding and the
    // reasoning, so future drift is visible.
    //
    //   #ngin-bar          — SE top bar: Back / SportsEngine / Sign In
    //   #fb-root           — Facebook SDK init div (empty, but produces noise downstream)
    //   #topNav            — full nav strip: site logo + tagline + search + main/sub/mobile menus
    //   #topNavPlaceholder — empty positional placeholder under topNav
    //   #PageSearch        — hidden page-search modal
    //   #overlay           — modal backdrop
    //   #lightbox          — lightbox modal
    //
    // VERIFIED (2026-06-24): one-shot Firecrawl spike confirmed the v2 /scrape
    // endpoint accepts CSS-style #id selectors in excludeTags despite the
    // OpenAPI spec wording ("Tags to exclude"); the spike dropped Home's
    // markdown from 12,829 → 11,434 chars, exactly the chrome budget, with
    // the body content intact.
    //
    // NOT included on purpose:
    //   #siteHeader        — empty on tbirdhoops but may render the org hero
    //                        banner on other SE itasca sites we haven't HTML-
    //                        inspected; excluding it there would strip a real
    //                        hero image. Add only after per-site validation.
    //
    // SE-itasca-scoped. The one waterworld site we've recon'd will need its
    // own selector set (or a theme-keyed map) once we extend cross-theme.
    private const EXCLUDE_TAGS = [
        '#ngin-bar',
        '#fb-root',
        '#topNav',
        '#topNavPlaceholder',
        '#PageSearch',
        '#overlay',
        '#lightbox',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.firecrawl.dev/v2',
    ) {}

    public function scrape(string $url): ?ScrapedPage
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Firecrawl API key not configured (services.firecrawl.api_key)');
        }

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(120) // HTTP-level timeout (s) — give Firecrawl's own timeout room
            ->throw()
            ->post(rtrim($this->baseUrl, '/').'/scrape', [
                'url' => $url,
                'formats' => self::FORMATS,
                // Strip nav/headers/footers/menus before markdown is generated.
                // Verified against the live v2 /scrape docs (2026-06-24): the
                // param sits at the root of the request body, default = true.
                // We set it EXPLICITLY because the first live tbirdhoops probe
                // came back with ~1400 chars of SE nav chrome prepended to
                // every page body — exactly what the doc-claimed default is
                // supposed to remove. Setting it on the wire makes the intent
                // un-mistakable and protects against any future default flip.
                'onlyMainContent' => true,
                // CSS #id selectors for SE itasca chrome onlyMainContent misses.
                // See the EXCLUDE_TAGS const docblock for rationale + verification.
                'excludeTags' => self::EXCLUDE_TAGS,
                // Cache control. Default = 172_800_000 ms (2 days). Setting
                // 0 means "use cache only if it's <0 ms old" — i.e. never;
                // every request triggers a fresh scrape. We need this for
                // INGEST because: (a) we run a site at most once per onboarding,
                // so cache hits give us zero value; (b) the second tbirdhoops
                // probe came back byte-for-byte identical to the first, which
                // we couldn't distinguish from "cache" without bypassing it.
                // Future: if we ever re-extract the same site twice (e.g. a
                // re-pull after an org-side fix), we still want fresh markdown
                // because the SE source page may have changed under us.
                'maxAge' => 0,
                'timeout' => self::TIMEOUT_MS,
            ]);

        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        if (($payload['success'] ?? false) !== true) {
            return null;
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        return new ScrapedPage(
            url: $this->stringOr($metadata['sourceURL'] ?? $metadata['url'] ?? null, $url),
            title: $this->stringOr($metadata['title'] ?? null, ''),
            markdown: $this->stringOr($data['markdown'] ?? null, ''),
            html: $this->stringOr($data['html'] ?? null, ''),
            image_urls: $this->stringListOr($data['images'] ?? null),
        );
    }

    private function stringOr(mixed $value, string $default): string
    {
        if (is_string($value)) {
            return $value;
        }
        // Firecrawl sometimes returns `title` as an array (multiple <title>);
        // pick the first string entry if so.
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) && $item !== '') {
                    return $item;
                }
            }
        }

        return $default;
    }

    /**
     * @return array<int, string>
     */
    private function stringListOr(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
