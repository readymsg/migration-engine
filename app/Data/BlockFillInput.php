<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// Everything one GeneratePageJob hands to the block-fill agent for a single
// page. Built inside the job by ContentLoader-resolving the body and
// pulling the per-conversion GlobalStyleBrief from the BlockFillContextStore.
//
// `body_markdown` is the REAL captured body verbatim — block-fill is per-
// page (no batching budget), so the full body goes in, no truncation. The
// agent's fabrication guard is "every prop value must be supported by
// body_markdown"; truncation would silently undermine that.
final class BlockFillInput extends Data
{
    /**
     * @param  array<int, string>  $body_image_urls  absolute URLs of inline images found in the captured body
     */
    public function __construct(
        public string $org_id,
        public string $page_slug,
        public Ir $ir,
        public GlobalStyleBrief $style_brief,
        public string $body_markdown,
        public array $body_image_urls,
    ) {}
}
