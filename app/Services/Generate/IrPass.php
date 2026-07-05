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
use App\Data\IrBriefDeriverInput;
use App\Data\IrChunkDesignerInput;
use App\Data\IrChunkDesignerResponse;
use App\Data\IrPassFailure;
use App\Data\IrPassResult;
use App\Data\IrPassStatus;
use App\Data\KeepPageContent;
use App\Data\Manifest;
use App\Data\SitePlan;
use Illuminate\Support\Str;
use Spatie\LaravelData\DataCollection;
use Throwable;

// Stage 3 GENERATE — IR pass orchestration. Two-agent chunked path:
//
//   1. ONE brief-deriver call (IrBriefDeriverAgent) — produces the
//      GlobalStyleBrief from a BOUNDED sample of representative pages
//      (depth-0 priority + fallback if depth-0 set is thin; capped at
//      BRIEF_SAMPLE_LIMIT pages). The brief is the singular cross-chunk
//      coherence anchor.
//
//   2. K chunked design calls (IrChunkDesignerAgent) — each receives
//      the brief as LOCKED input and designs IR for its chunk of pages.
//      Chunks are CHUNK_PAGE_LIMIT max so the model's output token
//      budget stays comfortable.
//
// FAITHFUL-REBUILD GUARANTEE — preserved end-to-end:
//
//   - Every keep-content page lands in IrPassResult.pages OR
//     IrPassResult.failures, exactly once. Diff-the-universe (full
//     keep_pages vs union of all chunks' returned pages) is the
//     authority; no chunk's success flag is.
//   - Per-chunk targeted retry on silent drops (mirrors the single-call
//     IR pass's retry pattern).
//   - Catastrophic chunk failure (throw) is caught and synthesized into
//     one IrPassFailure per page in that chunk — never silently absent.
//   - Content failures (no URL / no ContentRef / unreadable body) are
//     flagged BEFORE any LLM call, same as the single-call path.
//   - Per-page body-size guard: pages with markdown > MAX_BODY_BYTES
//     are flagged as content failures (separate reason); they never
//     reach the brief sample, never reach a chunk.
//   - Brief-deriver failure → empty-brief fallback + `*style_brief*`
//     sentinel failure; per-chunk IR design still runs (no coherence
//     anchor, but useful). Reviewer sees the flag, can re-run.
//     Partial output beats throwing away the per-page work over a
//     coherence-anchor failure.
//
// CRITICAL scoping (unchanged): IR is generated only for pages with
// disposition Keep AND kind=page. platform_dynamic / subsumed / park /
// drop / dynamic / external are all excluded.
final class IrPass
{
    // Max keep-content pages per chunked design call. 15 keeps output
    // token budget comfortable on Opus 4.8 (~150-500 tokens per page IR
    // design output × 15 ≈ 7.5K out of the 16K default ceiling, with
    // headroom). cjfl (34) → 3 chunks; 100-page sites → 7 chunks.
    public const CHUNK_PAGE_LIMIT = 15;

    // Brief-deriver sample size cap. Depth-0 pages are the voice
    // carriers (Home, About, Coaches, etc.); if a site has fewer
    // depth-0 pages, the sample fills out from deeper pages until it
    // hits this cap (or runs out). Either way the brief-deriver call
    // is BOUNDED — does not grow with N.
    public const BRIEF_SAMPLE_LIMIT = 12;

    // Per-page body-size guard. Pages whose captured markdown exceeds
    // this become content failures before reaching any LLM call —
    // prevents one absurdly huge body from blowing a chunk's input
    // budget or skewing the brief-deriver's sample.
    public const MAX_BODY_BYTES = 50_000;

    // Sentinel slug used for the brief-deriver-failure IrPassFailure
    // entry (when the brief-deriver throws or returns an empty brief).
    // Mirrors DraftLanding's '*' convention for lander-level failures
    // — same posture: a non-page-slug failure that's still visible in
    // the failures stream, doesn't correspond to any real page in the
    // rebuilt site.
    public const BRIEF_FAILURE_SLUG = '*style_brief*';

    public function __construct(
        private readonly IrBriefDeriverAgent $briefDeriver,
        private readonly IrChunkDesignerAgent $chunkDesigner,
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

        // Resolve bodies + apply the per-page body-size guard. Pages
        // without a readable body, or with a body that's too large to
        // safely send to the model, are flagged immediately — no LLM
        // call burned, no stub Ir produced.
        [$designablePages, $designableBodies, $contentFailures] = $this->resolveBodies($keepPages, $manifest);

        if ($designablePages === []) {
            return new IrPassResult(
                style_brief: $this->emptyStyleBrief($plan),
                pages: new DataCollection(Ir::class, []),
                failures: new DataCollection(IrPassFailure::class, $contentFailures),
                status: $contentFailures === [] ? IrPassStatus::Complete : IrPassStatus::Partial,
            );
        }

        // --- 1. Brief-deriver: ONE call against the bounded sample ----
        $briefResult = $this->runBriefDeriver($plan, $manifest, $designablePages, $designableBodies);
        $styleBrief = $briefResult['brief'];
        $briefFailure = $briefResult['failure']; // null if succeeded

        // --- 2. Chunked IR design: K calls, each with locked brief ---
        [$combinedPages, $designFailures] = $this->runChunkedDesign(
            $manifest,
            $plan,
            $styleBrief,
            $designablePages,
            $designableBodies,
        );

        /** @var array<int, IrPassFailure> $allFailures */
        $allFailures = array_merge($contentFailures, $designFailures);
        if ($briefFailure !== null) {
            $allFailures[] = $briefFailure;
        }

        $status = $this->resolveStatus($combinedPages, $designablePages, $allFailures);

        return new IrPassResult(
            style_brief: $styleBrief,
            pages: new DataCollection(Ir::class, $combinedPages),
            failures: new DataCollection(IrPassFailure::class, $allFailures),
            status: $status,
        );
    }

    /**
     * Resolve each keep page's captured body. Pages that fail any
     * resolution step (no URL, no ContentRef on manifest, content
     * extraction failure at ingest, body unreadable from disk, body
     * exceeds MAX_BODY_BYTES) become content-failure IrPassFailures
     * BEFORE any LLM call — never silently lost, never reach a chunk.
     *
     * @param  array<int, InventoryPage>  $keepPages
     * @return array{0: array<int, InventoryPage>, 1: array<int, KeepPageContent>, 2: array<int, IrPassFailure>}
     */
    private function resolveBodies(array $keepPages, Manifest $manifest): array
    {
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

            if (strlen($loaded->markdown) > self::MAX_BODY_BYTES) {
                $contentFailures[] = $this->failureFor(
                    $page,
                    sprintf(
                        'body exceeds per-page size cap (%d bytes > %d limit) — too large to safely send to the model; flag for human review',
                        strlen($loaded->markdown),
                        self::MAX_BODY_BYTES,
                    ),
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

        return [$designablePages, $designableBodies, $contentFailures];
    }

    /**
     * Build the brief sample (depth-0 priority + fallback) and call
     * the brief-deriver. Catches exceptions and returns an empty brief
     * + a sentinel IrPassFailure if the call fails.
     *
     * @param  array<int, InventoryPage>  $designablePages
     * @param  array<int, KeepPageContent>  $designableBodies
     * @return array{brief: GlobalStyleBrief, failure: ?IrPassFailure}
     */
    private function runBriefDeriver(
        SitePlan $plan,
        Manifest $manifest,
        array $designablePages,
        array $designableBodies,
    ): array {
        [$samplePages, $sampleBodies] = $this->buildBriefSample($designablePages, $designableBodies);

        $input = new IrBriefDeriverInput(
            org_id: $manifest->org_id,
            source_url: $manifest->source_url,
            brand: $manifest->brand,
            nav: $plan->nav,
            sample_pages: new DataCollection(InventoryPage::class, $samplePages),
            sample_bodies: new DataCollection(KeepPageContent::class, $sampleBodies),
            total_keep_pages: count($designablePages),
        );

        try {
            $brief = $this->briefDeriver->run($input);
        } catch (Throwable $e) {
            return [
                'brief' => $this->emptyStyleBrief($plan),
                'failure' => new IrPassFailure(
                    page_slug: self::BRIEF_FAILURE_SLUG,
                    page_title: 'Brief derivation',
                    page_node_id: null,
                    reason: 'brief-derivation-failed: '.$e->getMessage(),
                ),
            ];
        }

        // Empty-brief returns from a clean call are NOT treated as a
        // failure — the agent may legitimately produce a brief with
        // empty palette / voice on a thin site. Only a thrown exception
        // surfaces as a brief-derivation-failed flag. Downstream
        // SCORE & LOG can flag thin briefs as a soft signal.
        return ['brief' => $brief, 'failure' => null];
    }

    /**
     * Bounded sample for the brief-deriver. Depth-0 pages first (they
     * tend to be the voice carriers — Home, About, etc.), then deeper
     * pages in nav order if the depth-0 set is thin. Capped at
     * BRIEF_SAMPLE_LIMIT regardless of total site size.
     *
     * @param  array<int, InventoryPage>  $designablePages
     * @param  array<int, KeepPageContent>  $designableBodies
     * @return array{0: array<int, InventoryPage>, 1: array<int, KeepPageContent>}
     */
    private function buildBriefSample(array $designablePages, array $designableBodies): array
    {
        /** @var array<int, InventoryPage> $depth0 */
        $depth0 = [];
        /** @var array<int, KeepPageContent> $depth0Bodies */
        $depth0Bodies = [];
        /** @var array<int, InventoryPage> $deeper */
        $deeper = [];
        /** @var array<int, KeepPageContent> $deeperBodies */
        $deeperBodies = [];

        foreach ($designablePages as $i => $page) {
            if ($page->depth === 0) {
                $depth0[] = $page;
                $depth0Bodies[] = $designableBodies[$i];
            } else {
                $deeper[] = $page;
                $deeperBodies[] = $designableBodies[$i];
            }
        }

        // Sample = all depth-0 + deeper pages until cap. If site has
        // many depth-0 pages (rare but possible), the cap kicks in
        // there — the brief still gets representative content.
        /** @var array<int, InventoryPage> $samplePages */
        $samplePages = [];
        /** @var array<int, KeepPageContent> $sampleBodies */
        $sampleBodies = [];
        foreach ($depth0 as $i => $page) {
            if (count($samplePages) >= self::BRIEF_SAMPLE_LIMIT) {
                break;
            }
            $samplePages[] = $page;
            $sampleBodies[] = $depth0Bodies[$i];
        }
        foreach ($deeper as $i => $page) {
            if (count($samplePages) >= self::BRIEF_SAMPLE_LIMIT) {
                break;
            }
            $samplePages[] = $page;
            $sampleBodies[] = $deeperBodies[$i];
        }

        return [$samplePages, $sampleBodies];
    }

    /**
     * Run K chunked design calls. Per-chunk: try the call, diff
     * returned vs expected, retry missing once, synthesize failures
     * for any still-missing. Catastrophic chunk-level throw: one
     * IrPassFailure per page in the chunk. Diff-the-universe is the
     * authority; no chunk's success flag is.
     *
     * @param  array<int, InventoryPage>  $designablePages
     * @param  array<int, KeepPageContent>  $designableBodies
     * @return array{0: array<int, Ir>, 1: array<int, IrPassFailure>}
     */
    private function runChunkedDesign(
        Manifest $manifest,
        SitePlan $plan,
        GlobalStyleBrief $styleBrief,
        array $designablePages,
        array $designableBodies,
    ): array {
        $chunks = $this->chunkPages($designablePages, $designableBodies);
        $totalChunks = count($chunks);

        /** @var array<int, Ir> $combinedPages */
        $combinedPages = [];
        /** @var array<int, IrPassFailure> $allDesignFailures */
        $allDesignFailures = [];

        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkPages = $chunk[0];
            $chunkBodies = $chunk[1];

            $input = new IrChunkDesignerInput(
                org_id: $manifest->org_id,
                source_url: $manifest->source_url,
                brand: $manifest->brand,
                style_brief: $styleBrief,
                nav: $plan->nav,
                chunk_pages: new DataCollection(InventoryPage::class, $chunkPages),
                chunk_bodies: new DataCollection(KeepPageContent::class, $chunkBodies),
                chunk_index: $chunkIndex,
                total_chunks: $totalChunks,
            );

            try {
                $response = $this->chunkDesigner->run($input);
            } catch (Throwable $e) {
                // Catastrophic chunk failure — synthesize one failure
                // per page in this chunk so they're never silently
                // absent. The diff-the-universe contract REQUIRES
                // these surface; an uncaught throw would short-circuit
                // the loop and lose visibility into the remaining
                // chunks' work.
                foreach ($chunkPages as $page) {
                    $allDesignFailures[] = $this->failureFor(
                        $page,
                        sprintf(
                            'chunk #%d/%d threw: %s',
                            $chunkIndex + 1,
                            $totalChunks,
                            $e->getMessage(),
                        ),
                    );
                }

                continue;
            }

            // Per-chunk reconciliation: diff returned vs expected,
            // run a per-chunk targeted retry on silent drops.
            [$chunkAccepted, $chunkFailures] = $this->reconcileChunk(
                $manifest,
                $plan,
                $styleBrief,
                $chunkPages,
                $chunkBodies,
                $response,
                $chunkIndex,
                $totalChunks,
            );

            foreach ($chunkAccepted as $ir) {
                $combinedPages[] = $ir;
            }
            foreach ($chunkFailures as $f) {
                $allDesignFailures[] = $f;
            }
        }

        return [$combinedPages, $allDesignFailures];
    }

    /**
     * Per-chunk reconciliation: diff returned slugs vs chunk's
     * expected slugs. If missing, run ONE targeted retry with just
     * the missing pages from this chunk. Anything still missing
     * becomes an IrPassFailure.
     *
     * @param  array<int, InventoryPage>  $chunkPages
     * @param  array<int, KeepPageContent>  $chunkBodies
     * @return array{0: array<int, Ir>, 1: array<int, IrPassFailure>}
     */
    private function reconcileChunk(
        Manifest $manifest,
        SitePlan $plan,
        GlobalStyleBrief $styleBrief,
        array $chunkPages,
        array $chunkBodies,
        IrChunkDesignerResponse $firstResponse,
        int $chunkIndex,
        int $totalChunks,
    ): array {
        /** @var array<int, Ir> $accepted */
        $accepted = $firstResponse->pages->items();
        $missingAfterFirst = $this->findMissing($chunkPages, new DataCollection(Ir::class, $accepted));

        if ($missingAfterFirst === []) {
            return [$accepted, []];
        }

        // Targeted retry with ONLY the missing pages from THIS chunk.
        $retryBodies = $this->bodiesForPages($missingAfterFirst, $chunkPages, $chunkBodies);
        $retryInput = new IrChunkDesignerInput(
            org_id: $manifest->org_id,
            source_url: $manifest->source_url,
            brand: $manifest->brand,
            style_brief: $styleBrief,
            nav: $plan->nav,
            chunk_pages: new DataCollection(InventoryPage::class, $missingAfterFirst),
            chunk_bodies: new DataCollection(KeepPageContent::class, $retryBodies),
            chunk_index: $chunkIndex,
            total_chunks: $totalChunks,
        );

        try {
            $retryResponse = $this->chunkDesigner->run($retryInput);
            $accepted = array_merge($accepted, $retryResponse->pages->items());
        } catch (Throwable $e) {
            // Retry threw — keep what the first call accepted, the
            // still-missing become failures below.
        }

        $stillMissing = $this->findMissing($chunkPages, new DataCollection(Ir::class, $accepted));
        /** @var array<int, IrPassFailure> $chunkFailures */
        $chunkFailures = [];
        foreach ($stillMissing as $page) {
            $chunkFailures[] = new IrPassFailure(
                page_slug: PageSlug::of($page),
                page_title: $page->label,
                page_node_id: $page->page_node_id,
                reason: sprintf(
                    'missing from chunk #%d/%d initial response and from targeted retry',
                    $chunkIndex + 1,
                    $totalChunks,
                ),
            );
        }

        return [$accepted, $chunkFailures];
    }

    /**
     * Partition the keep pages into chunks of at most CHUNK_PAGE_LIMIT.
     * Preserves the input order (planner emits pages roughly in nav
     * BFS order — depth-0 first), so chunk 1 tends to carry the
     * site's voice anchors.
     *
     * @param  array<int, InventoryPage>  $pages
     * @param  array<int, KeepPageContent>  $bodies  parallel to $pages
     * @return array<int, array{0: array<int, InventoryPage>, 1: array<int, KeepPageContent>}>
     */
    private function chunkPages(array $pages, array $bodies): array
    {
        /** @var array<int, array{0: array<int, InventoryPage>, 1: array<int, KeepPageContent>}> $chunks */
        $chunks = [];
        $pageBatches = array_chunk($pages, self::CHUNK_PAGE_LIMIT);
        $bodyBatches = array_chunk($bodies, self::CHUNK_PAGE_LIMIT);
        foreach ($pageBatches as $i => $batch) {
            $chunks[] = [$batch, $bodyBatches[$i] ?? []];
        }

        return $chunks;
    }

    /**
     * @param  array<int, Ir>  $combinedPages
     * @param  array<int, InventoryPage>  $designablePages
     * @param  array<int, IrPassFailure>  $allFailures
     */
    private function resolveStatus(array $combinedPages, array $designablePages, array $allFailures): IrPassStatus
    {
        // Status: Complete (no failures, every designable page returned), Partial (any failure), Failed (zero pages designed across all chunks AND at least one design-call failure — every page failed wholesale).
        if ($allFailures === []) {
            return IrPassStatus::Complete;
        }
        if ($combinedPages === [] && $designablePages !== []) {
            // Total wipe — every designable page failed. This is the
            // chunked equivalent of the single-call over-capacity
            // catastrophe (cf. cjfl today): all chunks threw or all
            // pages dropped. Downstream stages chain this as Failed.
            return IrPassStatus::Failed;
        }

        return IrPassStatus::Partial;
    }

    /**
     * Returns the InventoryPages whose expected slug doesn't appear in
     * the returned pages collection. Slug match uses PageSlug — the
     * SAME helper the Anthropic agents use. Single-sourced; drift
     * would silently lose pages on the diff.
     *
     * @param  array<int, InventoryPage>  $expected
     * @param  DataCollection<int, Ir>  $returned
     * @return array<int, InventoryPage>
     */
    private function findMissing(array $expected, DataCollection $returned): array
    {
        /** @var array<string, true> $returnedSlugs */
        $returnedSlugs = [];
        /** @var array<int, Ir> $items */
        $items = $returned->items();
        foreach ($items as $ir) {
            $returnedSlugs[$ir->page_slug] = true;
        }

        $missing = [];
        foreach ($expected as $page) {
            if (! isset($returnedSlugs[PageSlug::of($page)])) {
                $missing[] = $page;
            }
        }

        return $missing;
    }

    /**
     * @param  array<int, InventoryPage>  $subset
     * @param  array<int, InventoryPage>  $allPages
     * @param  array<int, KeepPageContent>  $allBodies
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
