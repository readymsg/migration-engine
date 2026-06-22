<?php

declare(strict_types=1);

namespace App\Services\Extract;

use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;

// Real fetcher. Uses Guzzle's `on_stats` to capture the post-redirect URL
// — Laravel's HTTP client doesn't surface effective URI any other way.
final class HttpHtmlFetcher implements HtmlFetcher
{
    public function fetch(string $url): HtmlFetchResult
    {
        $finalUrl = $url;
        $response = Http::withUserAgent('TeamLinkt-MigrationEngine/0.1 (+https://teamlinkt.com)')
            ->withOptions([
                'allow_redirects' => ['max' => 10, 'strict' => false, 'referer' => true],
                'on_stats' => function (TransferStats $stats) use (&$finalUrl): void {
                    $finalUrl = (string) $stats->getEffectiveUri();
                },
            ])
            ->throw()
            ->get($url);

        return new HtmlFetchResult(
            requested_url: $url,
            final_url: $finalUrl,
            html: $response->body(),
            status: $response->status(),
        );
    }
}
