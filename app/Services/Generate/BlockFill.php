<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\BlockFillFailure;
use App\Data\BlockFillResult;
use App\Data\BlockFillStatus;
use App\Data\ContentExtractionFailure;
use App\Data\ContentRef;
use App\Data\FilledPage;
use App\Data\InventoryPage;
use App\Data\Ir;
use App\Data\IrPassResult;
use App\Data\IrPassStatus;
use App\Data\Manifest;
use App\Data\SitePlan;
use App\Jobs\GeneratePageJob;
use Illuminate\Support\Facades\Bus;
use Spatie\LaravelData\DataCollection;

// GENERATE stage 3 slice 2c orchestration. Takes the IR pass output plus
// the SitePlan + source Manifest, dispatches one GeneratePageJob per
// designed page via Bus::batch on a concurrency-capped Horizon queue, and
// reconciles the per-page result store into a BlockFillResult.
//
// FAITHFUL-REBUILD GUARANTEE: every page that was in IrPassResult.pages
// is in BlockFillResult.pages OR BlockFillResult.failures — exactly once.
// Reconciliation is the authority, NOT Bus::batch's success flag — the
// orchestrator diffs returned FilledPage slugs against the IR's slugs and
// turns any silent absence into an explicit BlockFillFailure. NEVER
// synthesises a placeholder FilledPage.
//
// IR-pass failures pass through untouched: an IrPassFailure becomes a
// BlockFillFailure with the upstream reason. The conversion's "every
// keep-content page accounted for" guarantee chains across stages.
//
// BUS::BATCH SEMANTICS — v1 assumes synchronous queue (test env uses
// QUEUE_CONNECTION=sync; prod will be Redis + Horizon, wired by step 6).
// In sync mode, dispatch() completes only after all jobs have executed,
// so the reconciliation read below sees a fully populated result store.
// When step 6 wires async dispatch, this class will be split into a
// pre-dispatch / post-batch pair driven by Bus::batch's then() / catch()
// — the reconciliation logic itself is unchanged.
final class BlockFill
{
    public function __construct(
        private readonly BlockFillContextStore $contextStore,
        private readonly BlockFillResultStore $resultStore,
    ) {}

    public function run(
        IrPassResult $irPass,
        SitePlan $plan,
        Manifest $manifest,
        string $conversionId,
    ): BlockFillResult {
        // IR pass aborted (e.g. over-capacity) → block-fill has nothing
        // to do. Surface a Failed status with one BlockFillFailure per
        // IR-pass failure so the conversion log sees every page once.
        if ($irPass->status === IrPassStatus::Failed) {
            return $this->failedFromIrPass($irPass);
        }

        /** @var array<int, Ir> $irPages */
        $irPages = $irPass->pages->items();

        if ($irPages === []) {
            return new BlockFillResult(
                style_brief: $irPass->style_brief,
                pages: new DataCollection(FilledPage::class, []),
                failures: $this->upstreamFailuresAsBlockFailures($irPass),
                status: $irPass->failures->count() > 0
                    ? BlockFillStatus::Partial
                    : BlockFillStatus::Complete,
            );
        }

        // Persist the style brief in the per-conversion side store so
        // every job can read it without bloating its serialized payload.
        $this->contextStore->put($conversionId, $irPass->style_brief);

        // Resolve each IR page to (InventoryPage, ContentRef). Pages
        // whose ContentRef cannot be resolved at pre-flight time fail
        // immediately — no job dispatched, no Sonnet call burned.
        $pageBySlug = $this->indexInventoryBySlug($plan);
        $refsByUrl = $this->indexContentRefs($manifest);
        $failureByUrl = $this->indexContentFailures($manifest);

        /** @var array<int, GeneratePageJob> $jobs */
        $jobs = [];
        /** @var array<int, BlockFillFailure> $preflightFailures */
        $preflightFailures = [];
        /** @var array<int, string> $expectedSlugs */
        $expectedSlugs = [];

        foreach ($irPages as $ir) {
            $expectedSlugs[] = $ir->page_slug;
            $page = $pageBySlug[$ir->page_slug] ?? null;
            if ($page === null) {
                $preflightFailures[] = new BlockFillFailure(
                    page_slug: $ir->page_slug,
                    page_title: $ir->page_title,
                    page_node_id: null,
                    reason: 'no matching InventoryPage in SitePlan.kept_pages for IR slug',
                );

                continue;
            }

            if ($page->url === null || $page->url === '') {
                $preflightFailures[] = new BlockFillFailure(
                    page_slug: $ir->page_slug,
                    page_title: $ir->page_title,
                    page_node_id: $page->page_node_id,
                    reason: 'InventoryPage has no source URL — cannot resolve content_ref',
                );

                continue;
            }

            $absoluteUrl = $this->absoluteUrl($manifest->source_url, $page->url);
            $ref = $refsByUrl[$absoluteUrl] ?? null;
            if ($ref === null) {
                $extractFailure = $failureByUrl[$absoluteUrl] ?? null;
                $reason = $extractFailure !== null
                    ? 'content was never captured (ingest failure: '.$extractFailure->reason.')'
                    : 'content was never captured (no content_ref on manifest)';
                $preflightFailures[] = new BlockFillFailure(
                    page_slug: $ir->page_slug,
                    page_title: $ir->page_title,
                    page_node_id: $page->page_node_id,
                    reason: $reason,
                );

                continue;
            }

            $jobs[] = new GeneratePageJob(
                conversion_id: $conversionId,
                page_slug: $ir->page_slug,
                ir: $ir,
                content_ref: $ref,
                org_id: $manifest->org_id,
            );
        }

        // Dispatch the batch. allowFailures(true) means one page's
        // terminal failure doesn't cancel the batch — every other page
        // still gets its chance. The job itself catches Throwable and
        // records a BlockFillFailure inline so failures land in the
        // store regardless of allowFailures semantics across drivers.
        if ($jobs !== []) {
            Bus::batch($jobs)
                ->name('block-fill:'.$conversionId)
                ->allowFailures()
                ->onQueue('block-fill')
                ->dispatch();
        }

        // Reconcile. Every expected slug must be in FilledPage OR
        // BlockFillFailure — anything missing becomes a synthetic
        // failure with a "silently absent" reason so the conversion
        // log can't quietly lose a page.
        return $this->reconcile(
            $irPass,
            $conversionId,
            $expectedSlugs,
            $preflightFailures,
        );
    }

    /**
     * @param  array<int, string>  $expectedSlugs
     * @param  array<int, BlockFillFailure>  $preflightFailures
     */
    private function reconcile(
        IrPassResult $irPass,
        string $conversionId,
        array $expectedSlugs,
        array $preflightFailures,
    ): BlockFillResult {
        /** @var array<int, FilledPage> $filledPages */
        $filledPages = [];
        /** @var array<int, BlockFillFailure> $failures */
        $failures = [];

        // Preflight failures are surfaced first — they never produced a
        // job, so the store has nothing under their slugs.
        /** @var array<string, true> $preflightSlugs */
        $preflightSlugs = [];
        foreach ($preflightFailures as $pf) {
            $failures[] = $pf;
            $preflightSlugs[$pf->page_slug] = true;
        }

        foreach ($expectedSlugs as $slug) {
            if (isset($preflightSlugs[$slug])) {
                continue;
            }

            $filled = $this->resultStore->getFilledPage($conversionId, $slug);
            if ($filled !== null) {
                $filledPages[] = $filled;

                continue;
            }

            $storedFailure = $this->resultStore->getFailure($conversionId, $slug);
            if ($storedFailure !== null) {
                $failures[] = $storedFailure;

                continue;
            }

            // Neither FilledPage nor BlockFillFailure for an expected
            // slug = silent absence. The job either never ran, was
            // killed mid-flight, or skipped a write. Surface explicitly.
            $failures[] = new BlockFillFailure(
                page_slug: $slug,
                page_title: $this->titleForSlug($irPass, $slug),
                page_node_id: null,
                reason: 'page silently absent from result store after batch (job never wrote)',
            );
        }

        // Chain in upstream IR-pass failures so the conversion log sees
        // every page exactly once across the two stages.
        foreach ($this->upstreamFailuresAsBlockFailures($irPass)->items() as $upstream) {
            /** @var BlockFillFailure $upstream */
            $failures[] = $upstream;
        }

        $status = $failures === []
            ? BlockFillStatus::Complete
            : BlockFillStatus::Partial;

        return new BlockFillResult(
            style_brief: $irPass->style_brief,
            pages: new DataCollection(FilledPage::class, $filledPages),
            failures: new DataCollection(BlockFillFailure::class, $failures),
            status: $status,
        );
    }

    private function failedFromIrPass(IrPassResult $irPass): BlockFillResult
    {
        $upstreamFailures = $this->upstreamFailuresAsBlockFailures($irPass);

        return new BlockFillResult(
            style_brief: $irPass->style_brief,
            pages: new DataCollection(FilledPage::class, []),
            failures: $upstreamFailures,
            status: BlockFillStatus::Failed,
        );
    }

    /**
     * @return DataCollection<int, BlockFillFailure>
     */
    private function upstreamFailuresAsBlockFailures(IrPassResult $irPass): DataCollection
    {
        /** @var array<int, BlockFillFailure> $out */
        $out = [];
        foreach ($irPass->failures as $f) {
            $out[] = new BlockFillFailure(
                page_slug: $f->page_slug,
                page_title: $f->page_title,
                page_node_id: $f->page_node_id,
                reason: 'ir-pass-failure: '.$f->reason,
            );
        }

        return new DataCollection(BlockFillFailure::class, $out);
    }

    /**
     * @return array<string, InventoryPage>
     */
    private function indexInventoryBySlug(SitePlan $plan): array
    {
        /** @var array<string, InventoryPage> $out */
        $out = [];
        /** @var array<int, InventoryPage> $pages */
        $pages = $plan->kept_pages->items();
        foreach ($pages as $p) {
            // PageSlug is the single source of truth — same helper the
            // IR pass uses, so the slug match here is the same slug the
            // agent saw upstream.
            $out[PageSlug::of($p)] = $p;
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

    private function titleForSlug(IrPassResult $irPass, string $slug): string
    {
        /** @var array<int, Ir> $pages */
        $pages = $irPass->pages->items();
        foreach ($pages as $ir) {
            if ($ir->page_slug === $slug) {
                return $ir->page_title;
            }
        }

        return $slug;
    }

    private function absoluteUrl(string $orgUrl, string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim($orgUrl, '/').'/'.ltrim($url, '/');
    }
}
