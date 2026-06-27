<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Bundles everything the IR pass agent needs in one DTO. The IrPass
// orchestration class filters a SitePlan down to Keep content pages
// before constructing this — platform_dynamic / subsumed / park / drop /
// dynamic / external are NEVER in `keep_pages`.
//
// keep_pages and keep_page_bodies are parallel collections keyed by slug:
// every page in keep_pages has exactly one matching KeepPageContent in
// keep_page_bodies (and vice versa). Pages whose body couldn't be loaded
// (no ContentRef, content-extraction failure at ingest, body unreadable)
// are NOT in keep_pages — IrPass turns them into IrPassFailure entries
// before constructing the input, so the agent never sees a body-less page.
final class IrPassInput extends Data
{
    /**
     * @param  DataCollection<int, NavItem>  $nav  echoed from SitePlan.nav; never re-derived by the LLM
     * @param  DataCollection<int, InventoryPage>  $keep_pages  content pages only (kind=page, action=Keep, body successfully loaded)
     * @param  DataCollection<int, KeepPageContent>  $keep_page_bodies  parallel to keep_pages; matched by PageSlug::of()
     */
    public function __construct(
        public string $org_id,
        public string $source_url,
        public Brand $brand,
        #[DataCollectionOf(NavItem::class)]
        public DataCollection $nav,
        #[DataCollectionOf(InventoryPage::class)]
        public DataCollection $keep_pages,
        #[DataCollectionOf(KeepPageContent::class)]
        public DataCollection $keep_page_bodies,
    ) {}
}
