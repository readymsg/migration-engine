<?php

declare(strict_types=1);

namespace App\Services\Extract;

use Illuminate\Support\Facades\Http;
use RuntimeException;

// Real Firecrawl HTTP client. STUB shape: the actual API endpoints and
// payload keys need to be confirmed against Firecrawl's live docs before
// production. The contract is what the test pins.
//
// TODO: validate request/response against the real Firecrawl spec.
final class HttpFirecrawlClient implements FirecrawlClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.firecrawl.dev/v1',
    ) {}

    public function submit(string $url): string
    {
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->throw()
            ->post("{$this->baseUrl}/scrape", ['url' => $url]);

        $data = $response->json();
        $jobId = is_array($data) ? ($data['job_id'] ?? $data['id'] ?? null) : null;
        if (! is_string($jobId) || $jobId === '') {
            throw new RuntimeException("Firecrawl submit returned no job id for {$url}");
        }

        return $jobId;
    }

    public function poll(string $jobId): ?ScrapedPage
    {
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->throw()
            ->get("{$this->baseUrl}/scrape/{$jobId}");

        $data = $response->json();
        if (! is_array($data)) {
            return null;
        }
        if (($data['status'] ?? null) !== 'completed') {
            return null;
        }

        return new ScrapedPage(
            url: $this->stringOr($data['url'] ?? null, ''),
            title: $this->stringOr($data['title'] ?? null, ''),
            markdown: $this->stringOr($data['markdown'] ?? null, ''),
            html: $this->stringOr($data['html'] ?? null, ''),
            image_urls: $this->stringListOr($data['images'] ?? null),
        );
    }

    private function stringOr(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
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
