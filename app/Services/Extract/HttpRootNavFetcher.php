<?php

declare(strict_types=1);

namespace App\Services\Extract;

use Illuminate\Support\Facades\Http;
use RuntimeException;

// Real HTTP-backed per-node fetcher. `/page/nav/<id>` is consistent across
// every SportsEngine theme observed in recon (itasca + waterworld) and
// returns the same JSON shape that itasca inlines as `var rootNav = {...}`.
// Always-API is intentional: it removes the theme dependency.
final class HttpRootNavFetcher implements RootNavFetcher
{
    public function fetchNode(string $orgUrl, int $pageNodeId): array
    {
        $endpoint = rtrim($orgUrl, '/')."/page/nav/{$pageNodeId}";
        $response = Http::acceptJson()
            ->withUserAgent('TeamLinkt-MigrationEngine/0.1 (+https://teamlinkt.com)')
            ->throw()
            ->get($endpoint);

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException("rootNav response was not a JSON object: {$endpoint}");
        }

        return $data;
    }
}
