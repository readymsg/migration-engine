<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\GlobalStyleBrief;
use App\Data\InventoryPage;
use App\Data\Ir;
use App\Data\IrPassAgentResponse;
use App\Data\IrPassFailure;
use App\Data\IrPassInput;
use App\Data\IrPassResult;
use App\Data\IrPassStatus;
use App\Data\Manifest;
use App\Data\SitePlan;
use Illuminate\Support\Str;
use Spatie\LaravelData\DataCollection;

// Stage 3 GENERATE — IR pass orchestration. Takes the SitePlan from PLAN
// plus the source Manifest, filters down to Keep content pages, and runs
// up to two structured Opus calls (via IrPassAgent) to produce a compact
// GlobalStyleBrief + per-page IR.
//
// FAITHFUL-REBUILD GUARANTEE — never silently drop a page, never stub
// one with placeholder content. The flow is validate → targeted retry →
// flag:
//
//   1. Call the agent once with every Keep content page.
//   2. Diff returned page slugs against expected slugs.
//   3. If anything is missing, call the agent a SECOND time with ONLY
//      the missing pages (full nav still passed for context). The
//      returned style brief from this call is discarded — the first
//      call's style brief is authoritative.
//   4. Diff again. Anything STILL missing lands in
//      IrPassResult.failures as an explicit IrPassFailure with reason
//      "missing from initial response and from targeted retry". The
//      result's status flips to Partial. The conversion can be
//      promoted/repaired downstream — what's NOT done here is fake
//      success by inventing a stub Ir.
//
// CRITICAL scoping: IR is generated only for pages with disposition
// Keep AND kind=page. platform_dynamic / subsumed / park / drop /
// dynamic / external are all excluded; see CLAUDE.md "GENERATE — IR
// pass" for the table.
final class IrPass
{
    public function __construct(
        private readonly IrPassAgent $agent,
    ) {}

    public function run(SitePlan $plan, Manifest $manifest): IrPassResult
    {
        $keepPages = $this->extractKeepContentPages($plan);

        if ($keepPages === []) {
            // No Keep content → skip the agent entirely.
            return new IrPassResult(
                style_brief: $this->emptyStyleBrief($plan),
                pages: new DataCollection(Ir::class, []),
                failures: new DataCollection(IrPassFailure::class, []),
                status: IrPassStatus::Complete,
            );
        }

        $firstInput = $this->buildInput($plan, $manifest, $keepPages);
        $firstResponse = $this->agent->run($firstInput);

        $missingAfterFirst = $this->findMissing($keepPages, $firstResponse->pages);

        if ($missingAfterFirst === []) {
            return new IrPassResult(
                style_brief: $firstResponse->style_brief,
                pages: $firstResponse->pages,
                failures: new DataCollection(IrPassFailure::class, []),
                status: IrPassStatus::Complete,
            );
        }

        // ONE targeted retry with the missing pages only. Full nav is
        // still passed so the agent can place the missing pages in
        // context. The retry's style brief is discarded — the first
        // call's style brief is authoritative across the whole site.
        $retryInput = $this->buildInput($plan, $manifest, $missingAfterFirst);
        $retryResponse = $this->agent->run($retryInput);

        /** @var array<int, Ir> $combinedPages */
        $combinedPages = array_merge(
            $firstResponse->pages->items(),
            $retryResponse->pages->items(),
        );
        $combinedCollection = new DataCollection(Ir::class, $combinedPages);

        $stillMissing = $this->findMissing($keepPages, $combinedCollection);

        $failures = [];
        foreach ($stillMissing as $page) {
            $failures[] = new IrPassFailure(
                page_slug: PageSlug::of($page),
                page_title: $page->label,
                page_node_id: $page->page_node_id,
                reason: 'missing from initial response and from targeted retry',
            );
        }

        return new IrPassResult(
            style_brief: $firstResponse->style_brief,
            pages: $combinedCollection,
            failures: new DataCollection(IrPassFailure::class, $failures),
            status: $failures === [] ? IrPassStatus::Complete : IrPassStatus::Partial,
        );
    }

    /**
     * @param  array<int, InventoryPage>  $keepPages
     */
    private function buildInput(SitePlan $plan, Manifest $manifest, array $keepPages): IrPassInput
    {
        return new IrPassInput(
            org_id: $manifest->org_id,
            source_url: $manifest->source_url,
            brand: $manifest->brand,
            nav: $plan->nav,
            keep_pages: new DataCollection(InventoryPage::class, $keepPages),
        );
    }

    /**
     * Returns the InventoryPages whose expected slug doesn't appear in the
     * agent's returned pages collection. Slug match uses PageSlug — the
     * SAME helper the AnthropicIrPassAgent uses when constructing the
     * prompt. Keeping this single-sourced is what makes the diff reliable;
     * if the two ever drift, pages would be silently lost.
     *
     * @param  array<int, InventoryPage>  $keepPages
     * @param  DataCollection<int, Ir>  $returned
     * @return array<int, InventoryPage>
     */
    private function findMissing(array $keepPages, DataCollection $returned): array
    {
        /** @var array<string, true> $returnedSlugs */
        $returnedSlugs = [];
        /** @var array<int, Ir> $items */
        $items = $returned->items();
        foreach ($items as $ir) {
            $returnedSlugs[$ir->page_slug] = true;
        }

        $missing = [];
        foreach ($keepPages as $page) {
            if (! isset($returnedSlugs[PageSlug::of($page)])) {
                $missing[] = $page;
            }
        }

        return $missing;
    }

    private function emptyStyleBrief(SitePlan $plan): GlobalStyleBrief
    {
        return new GlobalStyleBrief(
            brand_voice: '',
            palette: [],
            layout_conventions: [],
            nav: $plan->nav,
        );
    }

    /**
     * @return array<int, InventoryPage>
     */
    private function extractKeepContentPages(SitePlan $plan): array
    {
        /** @var array<string, DecisionEntry> $ledgerByTarget */
        $ledgerByTarget = [];
        foreach ($plan->ledger->entries as $entry) {
            $ledgerByTarget[$entry->target] = $entry;
        }

        /** @var array<int, InventoryPage> $keep */
        $keep = [];
        /** @var array<int, InventoryPage> $pages */
        $pages = $plan->kept_pages->items();
        foreach ($pages as $page) {
            if ($page->kind !== 'page') {
                continue;
            }
            $entry = $ledgerByTarget[$this->targetOf($page)] ?? null;
            if ($entry === null || $entry->action !== DecisionAction::Keep) {
                continue;
            }
            $keep[] = $page;
        }

        return $keep;
    }

    private function targetOf(InventoryPage $page): string
    {
        if ($page->url !== null && $page->url !== '') {
            return $page->url;
        }
        if ($page->page_node_id !== null) {
            return 'page_node:'.$page->page_node_id;
        }

        return 'label:'.Str::slug($page->label);
    }
}
