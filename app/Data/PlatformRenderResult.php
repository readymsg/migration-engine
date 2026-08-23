<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\SiteImport\Diagnostic;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Output of GENERATE stage 3 slice 2e — the deterministic platform-
// block renderer. Disjoint from AssemblyResult by construction:
// AssemblyResult's universe is content pages (FilledPages that came
// through block-fill); PlatformRenderResult's universe is PlatformDynamic
// ledger entries (Schedule, Roster, Teams, Calendar, etc.) that the IR
// pass filtered OUT before block-fill ever ran.
//
// FAITHFUL-REBUILD GUARANTEE — same posture as AssemblyResult: every
// PlatformDynamic ledger entry either becomes one PuckOutput in `pages`
// OR one PlatformRenderFailure in `failures`, exactly once. NEVER a
// blank PuckOutput, NEVER a silent absence.
//
// Slice 2f folds AssemblyResult.pages ⊎ PlatformRenderResult.pages into
// one `array<page_slug, page_json>` for ProductClient.createDraftSite()
// — but THAT'S 2F's JOB, not this slice's. This DTO is the seam.
final class PlatformRenderResult extends Data
{
    /**
     * @param  DataCollection<int, PuckOutput>  $pages
     * @param  DataCollection<int, PlatformRenderFailure>  $failures
     * @param  DataCollection<int, Diagnostic>  $diagnostics  info-severity signals from intentional skips
     *                                                        (reserved-route entity pages, etc.). NOT failures
     *                                                        — the page was deliberately not emitted per contract.
     */
    public function __construct(
        #[DataCollectionOf(PuckOutput::class)]
        public DataCollection $pages,
        #[DataCollectionOf(PlatformRenderFailure::class)]
        public DataCollection $failures,
        public PlatformRenderStatus $status,
        #[DataCollectionOf(Diagnostic::class)]
        public DataCollection $diagnostics = new DataCollection(Diagnostic::class, []),
    ) {}
}
