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
// `brand` and `style_brief` are PASSTHROUGH sidecars — same posture as
// block_issues_by_slug. They are NOT in page_map and they do NOT
// affect what createDraftSite receives (the rebuilt site is structurally
// unbranded in v1; the receiving product owns its own branding).
// They're carried here so that:
//   - SCORE & LOG (stage 4) has the data it needs for the
//     "extraction-grounded" structural-confidence signals BUILD.md:73
//     names — "logo found" reads brand.logo_asset_ref; palette / voice
//     telemetry reads style_brief.
//   - The throwaway preview can render preview-chrome that surfaces
//     what the engine extracted (org logo, palette, brand voice) so a
//     reviewer can see the brand signals the engine captured. The
//     preview chrome is metadata about the conversion — NOT a claim
//     the landed draft is branded.
final class ConversionResult extends Data
{
    /**
     * @param  array<string, array<string, mixed>>  $page_map  page_slug => Puck JSON, the payload submitted to createDraftSite when status != Failed
     * @param  DataCollection<int, ResolvedNavItem>  $nav  reconciled from SitePlan.nav; each entry's page_slug is the PageSlug::of() form that keys into page_map (or marked UnmatchedExternal / Unresolved)
     * @param  DataCollection<int, ConversionFailure>  $failures  unioned across upstream stages + any draft-landing-level failures
     * @param  array<string, array<int, AssemblyBlockIssue>>  $block_issues_by_slug  passthrough of AssemblyResult.block_issues_by_slug — per-block partial signals for SCORE & LOG
     * @param  array<string, array<int, ScrubIssue>>  $scrub_issues_by_slug  passthrough of AssemblyResult.scrub_issues_by_slug — SE-promo blocks and stale countdowns removed by the deterministic post-assembly scrubber. Visible in the conversion log so a reviewer can inspect exactly what was scrubbed.
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
        public Brand $brand,
        public GlobalStyleBrief $style_brief,
        public ?string $draft_id = null,
        public ?string $draft_url = null,
        public array $scrub_issues_by_slug = [],
    ) {}
}
