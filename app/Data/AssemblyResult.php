<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Output of GENERATE stage 3 slice 2d (deterministic assembler). The
// only schema-aware point in the engine: turns block-fill's FilledPages
// into the PuckOutput shape that ProductClient.createDraftSite() consumes.
//
// FAITHFUL-REBUILD GUARANTEE — same posture as IrPassResult and
// BlockFillResult: every input FilledPage either becomes a PuckOutput
// in `pages` OR an AssemblyFailure in `failures`, exactly once. NEVER a
// blank PuckOutput (a page where every block dropped becomes a
// failure). NEVER a silent absence.
//
// Diff universe is EXACTLY BlockFillResult.pages — the assembler does
// not consult SitePlan. Platform_dynamic pages were filtered out at IR
// pass and are LEGITIMATELY absent from block-fill output; the
// assembler must not phantom-fail them. Their PuckOutputs come from a
// separate PlatformBlockRenderer (slice 2e) and get folded in at draft-
// landing time.
//
// `block_issues_by_slug` is a sidecar map keeping PuckOutput pure (it's
// the ProductClient contract — non-Puck fields would pollute it). Keys
// are page_slugs of PARTIAL pages (those that emitted a PuckOutput but
// lost or substituted at least one block during coercion). Pages that
// assembled cleanly are not in the map; pages that failed entirely are
// in `failures`, not here.
//
// `style_brief` is a verbatim passthrough of BlockFillResult.style_brief
// — the assembler doesn't read it (the assembler is the schema-aware
// validation point for content, not a styling consumer), but the
// downstream draft-landing / SCORE & LOG layers need it on hand
// without re-reading the BlockFillResult. Same posture as
// block_issues_by_slug: sidecar passthrough, not a producer signal.
final class AssemblyResult extends Data
{
    /**
     * @param  DataCollection<int, PuckOutput>  $pages
     * @param  DataCollection<int, AssemblyFailure>  $failures
     * @param  array<string, array<int, AssemblyBlockIssue>>  $block_issues_by_slug  page_slug → ordered issues recorded on that page
     * @param  array<string, array<int, ScrubIssue>>  $scrub_issues_by_slug  page_slug → ordered scrub events (SE-promo blocks / stale countdowns removed). Populated by SePlatformBlockScrubber; default empty when the scrubber hasn't run.
     */
    public function __construct(
        #[DataCollectionOf(PuckOutput::class)]
        public DataCollection $pages,
        #[DataCollectionOf(AssemblyFailure::class)]
        public DataCollection $failures,
        public array $block_issues_by_slug,
        public AssemblyStatus $status,
        public GlobalStyleBrief $style_brief,
        public array $scrub_issues_by_slug = [],
    ) {}
}
