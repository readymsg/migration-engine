<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One kind=page node that SHOULD have had body content captured but didn't.
// Recorded explicitly on Manifest.content_failures so downstream (PLAN,
// GENERATE, ConversionLog) can see and flag the gap — v1 NEVER silently
// drops a page.
//
// Mirrors IrPassFailure's pattern from GENERATE: counts must tie out
// (kind=page nodes with URL == content_refs + content_failures).
final class ContentExtractionFailure extends Data
{
    public function __construct(
        public string $url,
        public string $page_title,
        public ?int $page_node_id,
        // Why the scrape failed. Examples:
        //   - 'firecrawl_returned_null'  (success=false, blocked page, etc.)
        //   - 'firecrawl_threw: <message>'
        //   - 'asset_upload_failed: <message>'
        public string $reason,
    ) {}
}
