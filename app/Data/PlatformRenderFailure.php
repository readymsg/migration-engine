<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One PlatformDynamic ledger entry that PlatformBlockRenderer could not
// turn into a PuckOutput. Surfaced explicitly so SCORE & LOG sees every
// expected page exactly once across the IR-pass → block-fill →
// assembler / platform-render chain — never silently skipped, never
// stubbed with a fake block.
//
// Three failure modes the renderer surfaces (see PlatformBlockRenderer):
//   1. ledger target doesn't match any kept_pages entry (defensive —
//      means upstream PLAN is broken)
//   2. PlatformDynamic action with a null platform_block_type
//      (defensive — planner doesn't currently emit this)
//   3. platform_block_type set but ComponentSchema::platformBlocks()
//      has no matching definition (means the enum and schema drifted)
//
// Failure modes 1+2 are unreachable under current invariants; they
// exist so that IF those invariants ever break, a reviewer sees the
// page as a loud failure instead of a silent absence.
final class PlatformRenderFailure extends Data
{
    public function __construct(
        public string $page_slug,
        public string $page_title,
        public ?int $page_node_id,
        public string $reason,
    ) {}
}
