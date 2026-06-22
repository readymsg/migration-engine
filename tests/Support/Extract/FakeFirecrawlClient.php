<?php

declare(strict_types=1);

namespace Tests\Support\Extract;

use App\Services\Extract\FirecrawlClient;
use App\Services\Extract\ScrapedPage;

// Synchronous in-memory fake. preload() pre-stages the ScrapedPage that
// poll() will return immediately after submit(). The test owns the data.
final class FakeFirecrawlClient implements FirecrawlClient
{
    /** @var array<string, ScrapedPage> */
    private array $pages = [];

    public function preload(string $url, ScrapedPage $page): void
    {
        $this->pages[$this->jobIdFor($url)] = $page;
    }

    public function submit(string $url): string
    {
        return $this->jobIdFor($url);
    }

    public function poll(string $jobId): ?ScrapedPage
    {
        return $this->pages[$jobId] ?? null;
    }

    private function jobIdFor(string $url): string
    {
        return 'fake_job_'.sha1($url);
    }
}
