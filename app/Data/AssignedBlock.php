<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One post-assembly, post-scrub block on a rebuilt page, tagged with the
// TeamLinkt block type it would land as. Type-only — NO prop mapping.
//
// Emitted by BlockTypeAssigner. Consumed by the coverage report to
// produce CAPTURED / SUPERSEDED / UNMAPPED tables per page.
final class AssignedBlock extends Data
{
    public function __construct(
        public string $page_slug,
        public string $page_title,
        public int $block_index,
        // Element kind counted from the source (e.g. 'hero', 'text',
        // 'image', 'card', 'button_group', 'columns', 'platform_schedule',
        // 'scrubbed_countdown', 'scrubbed_se_promo'). This is the input
        // side of the assignment — what the block was in the assembled /
        // scrubbed / platform stream BEFORE we mapped it to TeamLinkt.
        public string $source_kind,
        // TeamLinkt block type this element lands as. Null only when
        // disposition is Unmapped AND we chose not to place any block
        // (currently never — we always fall back to Text).
        public ?TeamlinktBlockType $teamlinkt_type,
        public TeamlinktBlockBucket $bucket,
        public AssignmentDisposition $disposition,
        // One-line human explanation. For Superseded, reads like "live
        // standings replaces static table". For Unmapped, reads like
        // "no confident mapping for 'Timeline'; falling back to Text".
        public string $reason,
        // Short snippet of the source content (for Captured & Unmapped),
        // OR of what was superseded (for Superseded). Bounded to 100
        // chars in the coverage report — kept full-length here.
        public ?string $source_snippet = null,
    ) {}
}
