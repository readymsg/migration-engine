<?php

declare(strict_types=1);

namespace Tests\Support\Extract;

use App\Services\Extract\FirecrawlClient;
use App\Services\Extract\ScrapedPage;

// Offline fake. preload() stages a ScrapedPage for a specific URL; scrape()
// returns it (or null when the URL hasn't been preloaded — which is the
// signal the SportNginExtractor uses to record an explicit content-
// extraction failure rather than silently dropping the page).
//
// withDefaultEcho() flips on a fallback: any URL not specifically preloaded
// gets a synthesized ScrapedPage with markdown derived from the last URL
// segment. Used by RealManifests so tests that build a Manifest from a
// rootnav fixture also get content_refs for every kind=page-with-url node,
// without the test needing to know the URLs ahead of time. Specific
// preload() and failHard() calls still take precedence.
final class FakeFirecrawlClient implements FirecrawlClient
{
    /** @var array<string, ScrapedPage> */
    private array $pages = [];

    /** @var array<string, true>  URLs the test wants to simulate a hard failure for (scrape() throws). */
    private array $shouldThrow = [];

    /** @var array<int, string>  URLs scrape() saw, in call order. */
    public array $seen = [];

    private bool $defaultEcho = false;

    public function preload(string $url, ScrapedPage $page): void
    {
        $this->pages[$url] = $page;
    }

    /** Mark a URL so that scrape() throws when called for it (simulates 5xx / timeout). */
    public function failHard(string $url): void
    {
        $this->shouldThrow[$url] = true;
    }

    /**
     * Turn on default-echo mode: any URL not specifically preloaded /
     * failed-hard returns a synthesized ScrapedPage. Specific preload()
     * and failHard() still take precedence.
     */
    public function withDefaultEcho(): self
    {
        $this->defaultEcho = true;

        return $this;
    }

    public function scrape(string $url): ?ScrapedPage
    {
        $this->seen[] = $url;

        if (isset($this->shouldThrow[$url])) {
            throw new \RuntimeException("FakeFirecrawlClient: simulated failure for {$url}");
        }

        if (isset($this->pages[$url])) {
            return $this->pages[$url];
        }

        if ($this->defaultEcho) {
            $slug = basename(rtrim(parse_url($url, PHP_URL_PATH) ?: '/', '/'));
            if ($slug === '' || $slug === '/') {
                $slug = 'page';
            }
            $title = ucwords(str_replace(['-', '_'], ' ', $slug));

            return new ScrapedPage(
                url: $url,
                title: $title,
                markdown: "# {$title}\n\nBody for {$title}.",
                html: "<h1>{$title}</h1><p>Body for {$title}.</p>",
                image_urls: [],
            );
        }

        return null;
    }
}
