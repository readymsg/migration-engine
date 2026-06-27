<?php

declare(strict_types=1);

namespace App\Services\Generate;

// Internal value object ContentLoader returns. Not a Data DTO because it
// never crosses a contract boundary — it's an in-process carrier from
// ContentLoader to IrPass while resolving a ContentRef into the markdown
// body and inline-image URLs Opus will design from.
//
// The Data DTO that DOES cross into the agent payload is KeepPageContent.
final class LoadedContent
{
    /**
     * @param  array<int, string>  $image_urls  absolute URLs found in the captured body
     */
    public function __construct(
        public readonly string $markdown,
        public readonly array $image_urls,
    ) {}
}
