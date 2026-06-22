<?php

declare(strict_types=1);

namespace App\Services\Extract;

// Async scrape client. BUILD.md: submit + poll, never block on one call.
// Injectable so the test can swap in a deterministic fake — there is NO
// live Firecrawl call from the test suite.
interface FirecrawlClient
{
    /**
     * Submit a URL for scraping. Returns the provider's job id.
     */
    public function submit(string $url): string;

    /**
     * Poll a job. Returns null while pending; ScrapedPage when ready.
     */
    public function poll(string $jobId): ?ScrapedPage;
}
