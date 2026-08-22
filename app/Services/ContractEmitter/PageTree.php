<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Diagnostic;
use App\Data\SiteImport\Page;

// Return type of PageTreeBuilder::build(). Content on each Page is
// EMPTY at this stage — Slice 9's ContractPayloadEmitter fills each
// page's content array via the PuckToContractMapper. Keeping tree
// construction and content mapping in separate slices means the
// slug/homepage/nav-order logic can be tested independently of any
// block-level concern.
final class PageTree
{
    /**
     * @param  array<int, Page>  $pages
     * @param  array<string, string>  $pageIdBySourceSlug  our-source-slug → contract-page-id lookup; Slice 9 needs this to route content by source slug
     * @param  array<int, Diagnostic>  $diagnostics
     */
    public function __construct(
        public readonly array $pages,
        public readonly array $pageIdBySourceSlug,
        public readonly array $diagnostics,
    ) {}
}
