<?php

declare(strict_types=1);

namespace App\Services\Extract;

// Single-page scrape client. Real Firecrawl v2 is SYNCHRONOUS — one POST
// returns the body. Returns null when Firecrawl reports success=false but
// did NOT throw; throws on transport / auth / HTTP errors. Injectable so
// tests run offline against a deterministic fake.
interface FirecrawlClient
{
    /**
     * Scrape one URL. Returns ScrapedPage with markdown + html on success.
     * Returns null when the page can't be retrieved (404, blocked, etc.)
     * but the API call itself succeeded — the SportNginExtractor records
     * those as content-extraction failures, never as silent absences.
     */
    public function scrape(string $url): ?ScrapedPage;
}
