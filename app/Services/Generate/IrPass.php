<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\ContentExtractionFailure;
use App\Data\ContentRef;
use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\GlobalStyleBrief;
use App\Data\InventoryPage;
use App\Data\Ir;
use App\Data\IrPassFailure;
use App\Data\IrPassInput;
use App\Data\IrPassResult;
use App\Data\IrPassStatus;
use App\Data\KeepPageContent;
use App\Data\Manifest;
use App\Data\SitePlan;
use Illuminate\Support\Str;
use Spatie\LaravelData\DataCollection;

// Stage 3 GENERATE — IR pass orchestration. Takes the SitePlan from PLAN
// plus the source Manifest, filters down to Keep content pages, resolves
// each page's REAL captured body from the scrapes disk, and runs up to two
// structured Opus calls (via IrPassAgent) to produce a compact
// GlobalStyleBrief + per-page IR.
//
// FAITHFUL-REBUILD GUARANTEE — never silently drop a page, never stub
// one with placeholder content. Two failure modes converge into the same
// IrPassFailure shape:
//
//   1. The agent silently drops a page. Flow: validate -> targeted retry
//      with ONLY the missing pages -> if still missing, flag.
//
//   2. The page has no readable captured body (no ContentRef on manifest,
//      or an explicit ContentExtractionFailure, or the body couldn't be
//      read back from the scrapes disk). These pages are NOT sent to
//      Opus — they're flagged directly. The IR pass NEVER asks the model
//      to design a page from nothing; a body-less page is a visible
//      failure for the reviewer to re-ingest, not a stub.
//
// SINGLE-CALL CAPACITY: v1 only supports a single Opus call for the whole
// site. If keep-content-page count exceeds SINGLE_CALL_PAGE_LIMIT, the
// pass FAILS LOUDLY (status=Failed, every page in failures with the
// over-capacity reason) rather than truncating. Chunking is slice 2b.
//
// CRITICAL scoping: IR is generated only for pages with disposition
// Keep AND kind=page. platform_dynamic / subsumed / park / drop /
// dynamic / external are all excluded; see CLAUDE.md "GENERATE — IR
// pass" for the table.
final class IrPass
{
    // Hard ceiling on keep-content-page count for the single-call IR pass.
    // Exceeded => abort, don't truncate. Chunking is a later slice; this
    // guard exists to make a too-big site visible the first time it's seen.
    public const SINGLE_CALL_PAGE_LIMIT = 25;

    public function __construct(
        private readonly IrPassAgent $agent,
        private readonly ContentLoader $contentLoader,
    ) {}

    public function run(SitePlan $plan, Manifest $manifest): IrPassResult
    {
        $keepPages = $this->extractKeepContentPages($plan);

        if ($keepPages === []) {
            return new IrPassResult(
                style_brief: $this->emptyStyleBrief($plan),
                pages: new DataCollection(Ir::class, []),
                failures: new DataCollection(IrPassFailure::class, []),
                status: IrPassStatus::Complete,
            );
        }

        if (count($keepPages) > self::SINGLE_CALL_PAGE_LIMIT) {
            return $this->overCapacityResult($plan, $keepPages);
        }

        // Resolve each keep page's body. Pages without a readable body are
        // flagged immediately (no Opus call burned, no stub Ir produced).
        $contentByUrl = $this->indexContentRefs($manifest);
        $failureByUrl = $this->indexContentFailures($manifest);

        /** @var array<int, InventoryPage> $designablePages */
        $designablePages = [];
        /** @var array<int, KeepPageContent> $designableBodies */
        $designableBodies = [];
        /** @var array<int, IrPassFailure> $contentFailures */
        $contentFailures = [];

        foreach ($keepPages as $page) {
            $url = $page->url;
            if ($url === null || $url === '') {
                $contentFailures[] = $this->failureFor(
                    $page,
                    'content was never captured (inventory page has no source URL)',
                );

                continue;
            }

            // ContentRef.url is the absolute URL the extractor scraped;
            // InventoryPage.url comes through as the relative path from
            // rootNav. Normalise here so the lookup tables actually match.
            $absoluteUrl = $this->absoluteUrl($manifest->source_url, $url);
            $contentRef = $contentByUrl[$absoluteUrl] ?? null;
            if ($contentRef === null) {
                $extractFailure = $failureByUrl[$absoluteUrl] ?? null;
                $reason = $extractFailure !== null
                    ? 'content was never captured (ingest failure: '.$extractFailure->reason.')'
                    : 'content was never captured (no content_ref on manifest)';
                $contentFailures[] = $this->failureFor($page, $reason);

                continue;
            }

            $loaded = $this->contentLoader->load($contentRef);
            if ($loaded === null) {
                $contentFailures[] = $this->failureFor(
                    $page,
                    'content_ref present but body could not be read back from scrapes disk',
                );

                continue;
            }

            $designablePages[] = $page;
            $designableBodies[] = new KeepPageContent(
                page_slug: PageSlug::of($page),
                page_title: $page->label,
                markdown: $loaded->markdown,
                image_urls: $loaded->image_urls,
            );
        }

        // Every keep page is a content failure — don't burn an Opus call
        // designing nothing. Return Partial with all flagged failures.
        if ($designablePages === []) {
            return new IrPassResult(
                style_brief: $this->emptyStyleBrief($plan),
                pages: new DataCollection(Ir::class, []),
                failures: new DataCollection(IrPassFailure::class, $contentFailures),
                status: IrPassStatus::Partial,
            );
        }

        $firstInput = $this->buildInput($plan, $manifest, $designablePages, $designableBodies);
        $firstResponse = $this->agent->run($firstInput);

        $missingAfterFirst = $this->findMissing($designablePages, $firstResponse->pages);

        if ($missingAfterFirst === []) {
            return new IrPassResult(
                style_brief: $firstResponse->style_brief,
                pages: $firstResponse->pages,
                failures: new DataCollection(IrPassFailure::class, $contentFailures),
                status: $contentFailures === [] ? IrPassStatus::Complete : IrPassStatus::Partial,
            );
        }

        // ONE targeted retry with the missing pages only. Full nav still
        // passed for context; the retry's style brief is discarded — the
        // first call's is authoritative across the whole site.
        $retryBodies = $this->bodiesForPages($missingAfterFirst, $designablePages, $designableBodies);
        $retryInput = $this->buildInput($plan, $manifest, $missingAfterFirst, $retryBodies);
        $retryResponse = $this->agent->run($retryInput);

        /** @var array<int, Ir> $combinedPages */
        $combinedPages = array_merge(
            $firstResponse->pages->items(),
            $retryResponse->pages->items(),
        );
        $combinedCollection = new DataCollection(Ir::class, $combinedPages);

        $stillMissing = $this->findMissing($designablePages, $combinedCollection);

        /** @var array<int, IrPassFailure> $agentFailures */
        $agentFailures = [];
        foreach ($stillMissing as $page) {
            $agentFailures[] = new IrPassFailure(
                page_slug: PageSlug::of($page),
                page_title: $page->label,
                page_node_id: $page->page_node_id,
                reason: 'missing from initial response and from targeted retry',
            );
        }

        $allFailures = array_merge($contentFailures, $agentFailures);

        return new IrPassResult(
            style_brief: $firstResponse->style_brief,
            pages: $combinedCollection,
            failures: new DataCollection(IrPassFailure::class, $allFailures),
            status: $allFailures === [] ? IrPassStatus::Complete : IrPassStatus::Partial,
        );
    }

    /**
     * @param  array<int, InventoryPage>  $keepPages
     * @param  array<int, KeepPageContent>  $keepPageBodies  parallel to $keepPages
     */
    private function buildInput(SitePlan $plan, Manifest $manifest, array $keepPages, array $keepPageBodies): IrPassInput
    {
        return new IrPassInput(
            org_id: $manifest->org_id,
            source_url: $manifest->source_url,
            brand: $manifest->brand,
            nav: $plan->nav,
            keep_pages: new DataCollection(InventoryPage::class, $keepPages),
            keep_page_bodies: new DataCollection(KeepPageContent::class, $keepPageBodies),
        );
    }

    /**
     * Builds a slug-indexed map from $allPages/$allBodies, then resolves
     * one body per page in $subset using PageSlug::of() as the key. Used
     * for the targeted retry so the retry input's keep_page_bodies stays
     * exactly aligned with its keep_pages.
     *
     * @param  array<int, InventoryPage>  $subset
     * @param  array<int, InventoryPage>  $allPages
     * @param  array<int, KeepPageContent>  $allBodies  parallel to $allPages
     * @return array<int, KeepPageContent>
     */
    private function bodiesForPages(array $subset, array $allPages, array $allBodies): array
    {
        /** @var array<string, KeepPageContent> $bodyBySlug */
        $bodyBySlug = [];
        foreach ($allPages as $i => $page) {
            $bodyBySlug[PageSlug::of($page)] = $allBodies[$i];
        }

        /** @var array<int, KeepPageContent> $out */
        $out = [];
        foreach ($subset as $page) {
            $slug = PageSlug::of($page);
            if (isset($bodyBySlug[$slug])) {
                $out[] = $bodyBySlug[$slug];
            }
        }

        return $out;
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

    /**
     * @return array<string, ContentRef>
     */
    private function indexContentRefs(Manifest $manifest): array
    {
        /** @var array<string, ContentRef> $out */
        $out = [];
        /** @var array<int, ContentRef> $items */
        $items = $manifest->content_refs->items();
        foreach ($items as $ref) {
            $out[$ref->url] = $ref;
        }

        return $out;
    }

    /**
     * @return array<string, ContentExtractionFailure>
     */
    private function indexContentFailures(Manifest $manifest): array
    {
        /** @var array<string, ContentExtractionFailure> $out */
        $out = [];
        if ($manifest->content_failures === null) {
            return $out;
        }
        /** @var array<int, ContentExtractionFailure> $items */
        $items = $manifest->content_failures->items();
        foreach ($items as $failure) {
            $out[$failure->url] = $failure;
        }

        return $out;
    }

    private function failureFor(InventoryPage $page, string $reason): IrPassFailure
    {
        return new IrPassFailure(
            page_slug: PageSlug::of($page),
            page_title: $page->label,
            page_node_id: $page->page_node_id,
            reason: $reason,
        );
    }

    /**
     * @param  array<int, InventoryPage>  $keepPages
     */
    private function overCapacityResult(SitePlan $plan, array $keepPages): IrPassResult
    {
        $reason = sprintf(
            'site exceeds single-call IR capacity, chunking not yet implemented (%d pages, limit %d)',
            count($keepPages),
            self::SINGLE_CALL_PAGE_LIMIT,
        );

        /** @var array<int, IrPassFailure> $failures */
        $failures = [];
        foreach ($keepPages as $page) {
            $failures[] = $this->failureFor($page, $reason);
        }

        return new IrPassResult(
            style_brief: $this->emptyStyleBrief($plan),
            pages: new DataCollection(Ir::class, []),
            failures: new DataCollection(IrPassFailure::class, $failures),
            status: IrPassStatus::Failed,
        );
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

    private function absoluteUrl(string $orgUrl, string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim($orgUrl, '/').'/'.ltrim($url, '/');
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
