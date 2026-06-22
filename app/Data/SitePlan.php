<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Output of stage 3 PLAN. Nav + final page set + the full ledger of decisions.
// Drops/parks are absent from `nav` / `kept_pages` but present in `ledger` —
// that's the reversibility guarantee (BUILD.md: drops are reversible, mark
// 'park', never delete).
final class SitePlan extends Data
{
    /**
     * @param  DataCollection<int, NavItem>  $nav  final top-level nav order
     * @param  DataCollection<int, InventoryPage>  $kept_pages  pages that survive into GENERATE
     */
    public function __construct(
        #[DataCollectionOf(NavItem::class)]
        public DataCollection $nav,
        #[DataCollectionOf(InventoryPage::class)]
        public DataCollection $kept_pages,
        public DecisionLedger $ledger,
    ) {}
}
