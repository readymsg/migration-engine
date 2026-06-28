<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Output of GENERATE stage 3 slice 2f — draft-landing. Per-conversion
// fold of everything downstream of PLAN/IR-pass/block-fill/assembler/
// platform-render. Consumed by SCORE & LOG (stage 4) to produce the
// final ConversionLog row.
//
// FAITHFUL-REBUILD GUARANTEE chains through here: every expected page
// across the pipeline is in page_map OR in failures, exactly once.
// Slug collisions between the content and platform streams are
// surfaced as a ConversionFailure rather than silently overwriting.
// NEVER a blank page in page_map.
//
// DRAFT-ONLY GUARANTEE: when status is Completed or Partial, draft-
// landing calls ProductClient::createDraftSite() (which is itself
// structurally draft-only — see ProductClient docblock) and populates
// draft_id + draft_url from its response. When status is Failed,
// createDraftSite is NOT called (an empty page_map would either error
// on the product side or create a phantom empty site — neither is
// useful) and both draft fields stay null.
final class ConversionResult extends Data
{
    /**
     * @param  array<string, array<string, mixed>>  $page_map  page_slug => Puck JSON, the payload submitted to createDraftSite when status != Failed
     * @param  DataCollection<int, ResolvedNavItem>  $nav  reconciled from SitePlan.nav; each entry's page_slug is the PageSlug::of() form that keys into page_map (or marked UnmatchedExternal / Unresolved)
     * @param  DataCollection<int, ConversionFailure>  $failures  unioned across upstream stages + any draft-landing-level failures
     * @param  array<string, array<int, AssemblyBlockIssue>>  $block_issues_by_slug  passthrough of AssemblyResult.block_issues_by_slug — per-block partial signals for SCORE & LOG
     */
    public function __construct(
        public string $conversion_id,
        public string $org_id,
        public array $page_map,
        #[DataCollectionOf(ResolvedNavItem::class)]
        public DataCollection $nav,
        #[DataCollectionOf(ConversionFailure::class)]
        public DataCollection $failures,
        public array $block_issues_by_slug,
        public ConversionStatus $status,
        public ?string $draft_id = null,
        public ?string $draft_url = null,
    ) {}
}
