<?php

declare(strict_types=1);

namespace App\Services\Extract;

use Spatie\LaravelData\Data;

// One scraped content page as returned by the Firecrawl client.
// The full bytes never make it into a Manifest — they're persisted to S3
// by the AssetUploader and referenced by ContentRef.
final class ScrapedPage extends Data
{
    /**
     * @param  array<int, string>  $image_urls  absolute URLs of inline images on the page
     */
    public function __construct(
        public string $url,
        public string $title,
        public string $markdown,
        public string $html,
        public array $image_urls = [],
    ) {}
}
