<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\BlockFillFailure;
use App\Data\BlockFillReconcileState;
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
use App\Jobs\ReconcileBlockFillJob;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Throwable;

// GENERATE stage 3 slice 2c orchestration, ASYNC-CORRECT version.
//
// Three public methods, split along the async boundary:
//
//   1. dispatch(irPass, plan, manifest, conversionId): void
//      - Preflight-resolves ContentRefs, builds the job list + preflight
//        failures.
//      - Writes the BlockFillReconcileState (IR-pass DTO + preflight
//        failures + expected slug set) to the result store so reconcile()
//        can run in a DIFFERENT PROCESS.
//      - Writes the style brief to the context store (jobs read from it).
//      - Dispatches Bus::batch with ->finally() that dispatches a
//        ReconcileBlockFillJob to invoke reconcile() on a worker.
//      - Under sync queue: dispatch() blocks until every per-page job
//        finishes AND the finally() callback fires AND
//        ReconcileBlockFillJob runs — the full pipeline is inline.
//      - Under async queue: dispatch() returns immediately; reconcile()
//        happens later on a worker.
//
//   2. reconcile(conversionId): BlockFillResult
//      - Idempotent: if a reconciled result already exists in the store,
//        returns it unchanged (no re-write, no side effect).
//      - Reads BlockFillReconcileState, walks the expected slug set,
//        collects FilledPage / BlockFillFailure per slug, surfaces
//        "silently absent" for any slug the store doesn't cover.
//      - Writes the reconciled BlockFillResult to the store (idempotency
//        marker AND downstream input).
//      - Safe to call multiple times per conversion. The scheduled
//        sweeper (engine:reconcile-stuck-conversions, 1-min) relies on
//        this idempotency.
//
//   3. run(irPass, plan, manifest, conversionId): BlockFillResult
//      - SYNC CONVENIENCE only. Calls dispatch() + reconcile(). Under
//        QUEUE_CONNECTION=sync, everything runs inline and the result
//        is populated when this returns. Under async queue, this WILL
//        return whatever reconcile sees at that moment — likely an
//        empty result, since the batch is still running on Redis. Do
//        NOT use run() from an async caller; use dispatch() and read
//        via the reconciled-result store when the chain proceeds.
//
// FAITHFUL-REBUILD GUARANTEE, UNCHANGED across sync/async: every page in
// IrPassResult.pages ends up in BlockFillResult.pages OR .failures —
// exactly once, never a stub, never silently absent. The RECONCILE method
// implements this contract; the DISPATCH method sets up the state
// reconcile needs.
//
// IR-pass failures chain in as `ir-pass-failure:`-prefixed
// BlockFillFailures so the conversion log sees every page once across
// stages.
final class BlockFill
{
    public function __construct(
        private readonly BlockFillContextStore $contextStore,
        private readonly BlockFillResultStore $resultStore,
    ) {}

    /**
     * Sync-only convenience. Under QUEUE_CONNECTION=sync, dispatch runs
     * the whole pipeline inline; reconcile then reads the populated
     * result store. Under async queue, this will return an empty /
     * partial result — do NOT use it from an async caller.
     */
    public function run(
        IrPassResult $irPass,
        SitePlan $plan,
        Manifest $manifest,
        string $conversionId,
    ): BlockFillResult {
        $this->dispatch($irPass, $plan, $manifest, $conversionId);

        // Under sync queue the reconciled result is already in the store
        // (dispatch → batch inline → finally inline → ReconcileBlockFillJob
        // inline → reconcile writes result). Under async it isn't yet —
        // fall through to a same-process reconcile() call, which is
        // idempotent, so a subsequent worker-side reconcile is a no-op.
        $reconciled = $this->resultStore->getReconciledResult($conversionId);
        if ($reconciled !== null) {
            return $reconciled;
        }

        return $this->reconcile($conversionId);
    }

    /**
     * Preflight + batch dispatch + finally-wired reconcile job. Void
     * return: callers get the reconciled result via reconcile() or the
     * store's getReconciledResult().
     */
    public function dispatch(
        IrPassResult $irPass,
        SitePlan $plan,
        Manifest $manifest,
        string $conversionId,
    ): void {
        // Clear any stale reconciled marker from a prior run of the same
        // conversion_id (re-runs after failure). Idempotency guard needs
        // to see a fresh conversion, not a stale success.
        $this->resultStore->forget($conversionId);

        // Upstream Failed → no jobs, no batch. Write the reconcile state
        // + reconciled result inline so downstream stages see a valid
        // BlockFillResult without waiting for a batch that will never
        // exist.
        if ($irPass->status === IrPassStatus::Failed) {
            $this->resultStore->putReconcileState(
                $conversionId,
                new BlockFillReconcileState(
                    conversion_id: $conversionId,
                    ir_pass: $irPass,
                    preflight_failures: new DataCollection(BlockFillFailure::class, []),
                    expected_slugs: [],
                ),
            );
            $this->resultStore->putReconciledResult(
                $conversionId,
                $this->failedFromIrPass($irPass),
            );

            return;
        }

        /** @var array<int, Ir> $irPages */
        $irPages = $irPass->pages->items();

        // Nothing to design (but upstream not Failed) → same shape:
        // reconcile-in-place, no batch.
        if ($irPages === []) {
            $emptyResult = new BlockFillResult(
                style_brief: $irPass->style_brief,
                pages: new DataCollection(FilledPage::class, []),
                failures: $this->upstreamFailuresAsBlockFailures($irPass),
                status: $irPass->failures->count() > 0
                    ? BlockFillStatus::Partial
                    : BlockFillStatus::Complete,
            );
            $this->resultStore->putReconcileState(
                $conversionId,
                new BlockFillReconcileState(
                    conversion_id: $conversionId,
                    ir_pass: $irPass,
                    preflight_failures: new DataCollection(BlockFillFailure::class, []),
                    expected_slugs: [],
                ),
            );
            $this->resultStore->putReconciledResult($conversionId, $emptyResult);

            return;
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

        // Write reconcile state BEFORE dispatch — reconcile (running on
        // a worker after batch.finally, OR from the scheduled sweeper)
        // reads it back. If reconcile can't read this state, the whole
        // conversion is unrecoverable, so this must precede any queue
        // interaction.
        $this->resultStore->putReconcileState(
            $conversionId,
            new BlockFillReconcileState(
                conversion_id: $conversionId,
                ir_pass: $irPass,
                preflight_failures: new DataCollection(BlockFillFailure::class, $preflightFailures),
                expected_slugs: $expectedSlugs,
            ),
        );

        // If every IR page hit preflight failure, no jobs to run. Reconcile
        // inline (nothing else will trigger it) so the conversion is
        // immediately readable.
        if ($jobs === []) {
            $this->reconcile($conversionId);

            return;
        }

        // Dispatch the batch. allowFailures() means one page's terminal
        // failure doesn't cancel the batch — every other page still gets
        // its chance. The job itself catches Throwable and records a
        // BlockFillFailure inline so failures land in the store regardless
        // of allowFailures semantics across drivers.
        //
        // finally() fires after every job completes (success or failure)
        // — the load-bearing wiring. Uses finally not then so Partial
        // conversions still get reconciled and reach downstream stages.
        //
        // If this finally callback ITSELF fails to fire (batch's own
        // bookkeeping breaks, Redis blip, worker OOM at exactly the wrong
        // moment), the scheduled sweeper is the correctness backstop.
        Bus::batch($jobs)
            ->name('block-fill:'.$conversionId)
            ->allowFailures()
            ->onQueue('block-fill')
            ->finally(function (Batch $batch) use ($conversionId): void {
                ReconcileBlockFillJob::dispatch($conversionId);
            })
            ->dispatch();
    }

    /**
     * Idempotent reconcile. If a reconciled result already exists in the
     * store, returns it unchanged. Otherwise reads the reconcile state,
     * walks the expected slug set, produces a BlockFillResult, and
     * writes it to the store.
     *
     * @throws RuntimeException when no reconcile state exists — either
     *                          dispatch() was never called or the store
     *                          was cleared. Reviewer must re-run the
     *                          conversion.
     */
    public function reconcile(string $conversionId): BlockFillResult
    {
        // Idempotency guard: reconciled already, return it. The scheduled
        // sweeper relies on this — it fires every minute and re-invokes
        // reconcile for any conversion whose state exists but result
        // doesn't; re-running against an already-reconciled conversion
        // is safe and cheap.
        $existing = $this->resultStore->getReconciledResult($conversionId);
        if ($existing !== null) {
            return $existing;
        }

        $state = $this->resultStore->getReconcileState($conversionId);
        if ($state === null) {
            throw new RuntimeException(
                "BlockFill::reconcile() called for conversion '{$conversionId}' "
                .'but no reconcile state exists — either dispatch() was never called '
                .'or the state was cleared. Re-run the conversion.'
            );
        }

        $result = $this->reconcileFromState($state);
        $this->resultStore->putReconciledResult($conversionId, $result);

        return $result;
    }

    private function reconcileFromState(BlockFillReconcileState $state): BlockFillResult
    {
        /** @var array<int, FilledPage> $filledPages */
        $filledPages = [];
        /** @var array<int, BlockFillFailure> $failures */
        $failures = [];

        // Preflight failures are surfaced first — they never produced a
        // job, so the store has nothing under their slugs.
        /** @var array<string, true> $preflightSlugs */
        $preflightSlugs = [];
        /** @var array<int, BlockFillFailure> $preflight */
        $preflight = $state->preflight_failures->items();
        foreach ($preflight as $pf) {
            $failures[] = $pf;
            $preflightSlugs[$pf->page_slug] = true;
        }

        foreach ($state->expected_slugs as $slug) {
            if (isset($preflightSlugs[$slug])) {
                continue;
            }

            $filled = $this->resultStore->getFilledPage($state->conversion_id, $slug);
            if ($filled !== null) {
                $filledPages[] = $filled;

                continue;
            }

            $storedFailure = $this->resultStore->getFailure($state->conversion_id, $slug);
            if ($storedFailure !== null) {
                $failures[] = $storedFailure;

                continue;
            }

            // Neither FilledPage nor BlockFillFailure for an expected
            // slug = silent absence. The job either never ran, was
            // killed mid-flight, or skipped a write. Surface explicitly.
            $failures[] = new BlockFillFailure(
                page_slug: $slug,
                page_title: $this->titleForSlug($state->ir_pass, $slug),
                page_node_id: null,
                reason: 'page silently absent from result store after batch (job never wrote)',
            );
        }

        // Chain in upstream IR-pass failures so the conversion log sees
        // every page exactly once across the two stages.
        /** @var array<int, BlockFillFailure> $upstream */
        $upstream = $this->upstreamFailuresAsBlockFailures($state->ir_pass)->items();
        foreach ($upstream as $u) {
            $failures[] = $u;
        }

        $status = $failures === []
            ? BlockFillStatus::Complete
            : BlockFillStatus::Partial;

        return new BlockFillResult(
            style_brief: $state->ir_pass->style_brief,
            pages: new DataCollection(FilledPage::class, $filledPages),
            failures: new DataCollection(BlockFillFailure::class, $failures),
            status: $status,
        );
    }

    private function failedFromIrPass(IrPassResult $irPass): BlockFillResult
    {
        return new BlockFillResult(
            style_brief: $irPass->style_brief,
            pages: new DataCollection(FilledPage::class, []),
            failures: $this->upstreamFailuresAsBlockFailures($irPass),
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
