<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One recorded scrub on a single top-level block during the
// SePlatformBlockScrubber pass (post-assembly, pre-draft-landing).
// Surfaced via AssemblyResult.scrub_issues_by_slug so SCORE & LOG
// can annotate the conversion + a reviewer can inspect exactly what
// content was removed and undo a false positive.
//
// Every scrub emits an issue. Silent scrubbing is FORBIDDEN — it
// would be silent-loss pointed at the wrong target, which is worse
// than missing a promo variant. The scrubber's discipline: err TIGHT
// on detection, but every drop is logged.
//
// `dropped_content_summary` is a short human-readable description of
// what was inside the dropped block (e.g. "3 buttons: 2 app-store
// hrefs + 1 promo label" or "3 nested Cards with countdown text"),
// suitable for surfacing in the conversion log without dumping the
// full block JSON. The raw dropped payload is not preserved — we
// don't need to reconstruct it, only to be visible.
final class ScrubIssue extends Data
{
    public function __construct(
        public int $block_index,          // position in ORIGINAL content array before scrubbing
        public string $component_type,    // 'ButtonGroup', 'Columns', 'Card', etc.
        public ScrubKind $kind,
        public string $reason,
        public string $dropped_content_summary,
    ) {}
}
