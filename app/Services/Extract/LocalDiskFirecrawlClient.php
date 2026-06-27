<?php

declare(strict_types=1);

namespace App\Services\Extract;

use Illuminate\Support\Facades\Storage;
use JsonException;

// FirecrawlClient impl that reads previously-captured scrapes from a
// Laravel disk instead of calling the Firecrawl API. Used by the
// fixture-capture artisan command so the one-time block-fill capture
// doesn't re-spend Firecrawl credit on URLs whose scrape JSONs already
// live in `storage/app/private/orgs/<org>/scrapes/{sha1(url)}.json`.
//
// scrape() returns null when the disk has no matching file — which
// causes SportNginExtractor to record an explicit
// ContentExtractionFailure (the same path as a live Firecrawl miss),
// so the capture command surfaces gaps loudly instead of silently
// dropping pages.
final class LocalDiskFirecrawlClient implements FirecrawlClient
{
    public function __construct(
        private readonly string $orgId,
        private readonly string $disk = 'local',
    ) {}

    public function scrape(string $url): ?ScrapedPage
    {
        $key = sprintf('orgs/%s/scrapes/%s.json', $this->orgId, sha1($url));

        $storage = Storage::disk($this->disk);
        if (! $storage->exists($key)) {
            return null;
        }

        $raw = $storage->get($key);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<int, string> $imageUrls */
        $imageUrls = [];
        $rawImages = $decoded['image_urls'] ?? [];
        if (is_array($rawImages)) {
            foreach ($rawImages as $imageUrl) {
                if (is_string($imageUrl) && $imageUrl !== '') {
                    $imageUrls[] = $imageUrl;
                }
            }
        }

        return new ScrapedPage(
            url: is_string($decoded['url'] ?? null) ? $decoded['url'] : $url,
            title: is_string($decoded['title'] ?? null) ? $decoded['title'] : '',
            markdown: is_string($decoded['markdown'] ?? null) ? $decoded['markdown'] : '',
            html: is_string($decoded['html'] ?? null) ? $decoded['html'] : '',
            image_urls: $imageUrls,
        );
    }
}
