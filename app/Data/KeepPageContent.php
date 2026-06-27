<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One Keep content page's REAL captured body, passed alongside InventoryPage
// metadata to the IR pass agent so Opus can design architecture from the
// page's actual content instead of from its nav label alone.
//
// page_slug MUST match the slug PageSlug::of() derives from the
// corresponding InventoryPage in IrPassInput.keep_pages — IrPass uses slugs
// as the cross-collection key, and drift here would silently mis-pair body
// with page. IrPass::buildInput is the only place that mints these, so a
// single producer + single slug source keeps the invariant tight.
//
// Separate from InventoryPage on purpose: InventoryPage describes nav-tree
// position (label/url/depth/kind/...) and is owned by PLAN; KeepPageContent
// carries the body the extractor captured and is owned by GENERATE. Mixing
// them would let nav-traversal code accidentally pull bodies it doesn't
// need.
final class KeepPageContent extends Data
{
    /**
     * @param  array<int, string>  $image_urls  absolute URLs of inline images found in the captured body
     */
    public function __construct(
        public string $page_slug,
        public string $page_title,
        public string $markdown,
        public array $image_urls = [],
    ) {}
}
