<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Input to the brief-deriver agent — produces ONE GlobalStyleBrief for
// the whole site that every IR-design chunk receives as locked input.
//
// IMPORTANT — the input is BOUNDED by construction: `sample_pages` is a
// fixed-size representative slice of the site (depth-0 pages + a few
// sampled deeper ones, capped at ~10-12 pages regardless of total site
// size). This is what makes the brief-deriver a bounded call — if its
// input scaled with N (e.g. "1KB truncated body of every page"), the
// brief-deriver would become the same unbounded-call problem chunking
// exists to fix.
//
// Sample pages get their FULL body (not truncated) because voice/palette/
// convention inference needs prose anchors. With ~10-12 pages × ~5KB
// median = ~60KB input + nav + brand metadata — well within Opus's
// 200K input window even on very large sites.
//
// IrPass orchestration applies the same per-page body-size guard
// (50KB cap) to the sample as it does to chunked pages: a single huge
// page can't blow the brief-deriver's input budget either.
final class IrBriefDeriverInput extends Data
{
    /**
     * @param  DataCollection<int, NavItem>  $nav  echoed from SitePlan.nav
     * @param  DataCollection<int, InventoryPage>  $sample_pages  bounded sample (depth-0 priority, fallback to deeper pages if depth-0 set is thin); capped page count
     * @param  DataCollection<int, KeepPageContent>  $sample_bodies  parallel to sample_pages; full bodies (subject to the per-page body-size cap)
     */
    public function __construct(
        public string $org_id,
        public string $source_url,
        public Brand $brand,
        #[DataCollectionOf(NavItem::class)]
        public DataCollection $nav,
        #[DataCollectionOf(InventoryPage::class)]
        public DataCollection $sample_pages,
        #[DataCollectionOf(KeepPageContent::class)]
        public DataCollection $sample_bodies,
        public int $total_keep_pages,
    ) {}
}
