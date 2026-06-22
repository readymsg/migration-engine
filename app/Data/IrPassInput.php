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
final class IrPassInput extends Data
{
    /**
     * @param  DataCollection<int, NavItem>  $nav  echoed from SitePlan.nav; never re-derived by the LLM
     * @param  DataCollection<int, InventoryPage>  $keep_pages  content pages only (kind=page, action=Keep)
     */
    public function __construct(
        public string $org_id,
        public string $source_url,
        public Brand $brand,
        #[DataCollectionOf(NavItem::class)]
        public DataCollection $nav,
        #[DataCollectionOf(InventoryPage::class)]
        public DataCollection $keep_pages,
    ) {}
}
