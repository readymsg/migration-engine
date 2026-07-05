<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Input to ONE chunked IR-design call. Produces IR for chunk_pages only;
// the GlobalStyleBrief is LOCKED input (designed by the brief-deriver
// in a prior call) — every chunk's IR design conforms to the same
// style brief, which is how cross-chunk coherence is preserved.
//
// chunk_pages is bounded by IrPass::CHUNK_PAGE_LIMIT (15). Per-page
// body-size guard already applied upstream — huge bodies are filtered
// to content failures before chunking, never reach a chunk.
//
// `chunk_index` and `total_chunks` are passed for prompt context
// ("you're designing pages 16-30 of a 34-page site") so the model
// understands its slice; reconciliation does NOT use them — diff is
// authoritative per-chunk on returned slug set.
final class IrChunkDesignerInput extends Data
{
    /**
     * @param  GlobalStyleBrief  $style_brief  LOCKED input from brief-deriver; the chunk MUST conform to this brief and MUST NOT propose its own voice/palette/conventions
     * @param  DataCollection<int, NavItem>  $nav  full-site nav (echoed from SitePlan.nav); designer sees the WHOLE site's nav structure even though it only designs its chunk's pages
     * @param  DataCollection<int, InventoryPage>  $chunk_pages  the slice of keep-content pages this chunk designs (≤ CHUNK_PAGE_LIMIT)
     * @param  DataCollection<int, KeepPageContent>  $chunk_bodies  parallel to chunk_pages; full bodies
     */
    public function __construct(
        public string $org_id,
        public string $source_url,
        public Brand $brand,
        public GlobalStyleBrief $style_brief,
        #[DataCollectionOf(NavItem::class)]
        public DataCollection $nav,
        #[DataCollectionOf(InventoryPage::class)]
        public DataCollection $chunk_pages,
        #[DataCollectionOf(KeepPageContent::class)]
        public DataCollection $chunk_bodies,
        public int $chunk_index,
        public int $total_chunks,
    ) {}
}
