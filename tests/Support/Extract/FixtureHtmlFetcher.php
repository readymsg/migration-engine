<?php

declare(strict_types=1);

namespace Tests\Support\Extract;

use App\Services\Extract\HtmlFetcher;
use App\Services\Extract\HtmlFetchResult;
use RuntimeException;

// Returns saved HTML keyed by requested URL. Use `preloadFromFile()` to
// stage a fixture and `final_url` to model a redirect (e.g. strikersbaseball
// → langdondiamonds).
final class FixtureHtmlFetcher implements HtmlFetcher
{
    /** @var array<string, HtmlFetchResult> */
    private array $responses = [];

    public function preload(string $requestedUrl, HtmlFetchResult $result): void
    {
        $this->responses[$requestedUrl] = $result;
    }

    public function preloadFromFile(string $requestedUrl, string $finalUrl, string $path, int $status = 200): void
    {
        $html = file_get_contents($path);
        if ($html === false) {
            throw new RuntimeException("Could not read fixture: {$path}");
        }
        $this->preload($requestedUrl, new HtmlFetchResult(
            requested_url: $requestedUrl,
            final_url: $finalUrl,
            html: $html,
            status: $status,
        ));
    }

    public function fetch(string $url): HtmlFetchResult
    {
        if (! array_key_exists($url, $this->responses)) {
            throw new RuntimeException("No fixture HTML for: {$url}");
        }

        return $this->responses[$url];
    }
}
