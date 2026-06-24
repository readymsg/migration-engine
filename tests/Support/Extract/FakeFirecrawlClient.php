<?php

declare(strict_types=1);

namespace Tests\Support\Extract;

use App\Services\Extract\FirecrawlClient;
use App\Services\Extract\ScrapedPage;

// Offline fake. preload() stages a ScrapedPage for a specific URL; scrape()
// returns it (or null when the URL hasn't been preloaded — which is the
// signal the SportNginExtractor uses to record an explicit content-
// extraction failure rather than silently dropping the page).
final class FakeFirecrawlClient implements FirecrawlClient
{
    /** @var array<string, ScrapedPage> */
    private array $pages = [];

    /** @var array<string, true>  URLs the test wants to simulate a hard failure for (scrape() throws). */
    private array $shouldThrow = [];

    /** @var array<int, string>  URLs scrape() saw, in call order. */
    public array $seen = [];

    public function preload(string $url, ScrapedPage $page): void
    {
        $this->pages[$url] = $page;
    }

    /** Mark a URL so that scrape() throws when called for it (simulates 5xx / timeout). */
    public function failHard(string $url): void
    {
        $this->shouldThrow[$url] = true;
    }

    public function scrape(string $url): ?ScrapedPage
    {
        $this->seen[] = $url;

        if (isset($this->shouldThrow[$url])) {
            throw new \RuntimeException("FakeFirecrawlClient: simulated failure for {$url}");
        }

        return $this->pages[$url] ?? null;
    }
}
