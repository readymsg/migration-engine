<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\ConversionContext;
use App\Data\ConversionPipelineStage;
use App\Services\Conversion\ConversionContextStore;
use App\Services\Conversion\ConversionCostGuard;
use App\Services\Conversion\ConversionStatusStore;
use App\Services\Extract\Extractor;
use App\Services\Generate\BlockFill;
use App\Services\Generate\IrPass;
use App\Services\Plan\Planner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

// The step-6 pre-batch stages of the conversion pipeline as a single
// queueable job. Runs INGEST → PLAN → IR-pass INLINE, writes the
// ConversionContext (hand-off to FinalizeConversionJob), then fires
// BlockFill::dispatch which schedules the per-page fan-out batch
// on Redis.
//
// This job's role ends when BlockFill::dispatch returns. The batch
// runs on Horizon workers; batch->finally() dispatches
// ReconcileBlockFillJob; ReconcileBlockFillJob dispatches
// FinalizeConversionJob after reconcile completes. That downstream
// chain lives elsewhere.
//
// FAILURE POSTURE (load-bearing for the demo):
//
//   1. handle() wraps every stage boundary in status writes so the
//      polling /status endpoint sees progress in real time.
//   2. ANY throw before dispatching the batch surfaces as a Failed
//      status via failed() below — never a hung "stage: ingest"
//      forever. This is the demo-critical no-silent-hang contract.
//   3. tries=1: conversion re-runs are a user action (they hit the
//      convert button again), not a queue-level retry. A single
//      transient blip failing the whole pipeline is preferable to
//      silently retrying a chain that costs $2-6 in Sonnet per run.
//      GeneratePageJob's per-page $tries=1 is the same posture at
//      finer granularity; ReconcileBlockFillJob has $tries=3 because
//      it's cheap + idempotent + losing it strands the conversion.
//
// timeout=1200 (20 min): INGEST (~30s Firecrawl scrape) + PLAN
// (~10-30s Haiku classification) + IR-pass (~1-3 min Opus for a
// 30-40 page site) + BlockFill::dispatch (batch-write, ~1s). 20 min
// covers the pessimistic case with headroom for larger sites.
final class ConversionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(
        public readonly string $conversion_id,
        public readonly string $url,
    ) {}

    public function handle(
        Extractor $extractor,
        Planner $planner,
        IrPass $irPass,
        BlockFill $blockFill,
        ConversionStatusStore $statusStore,
        ConversionContextStore $contextStore,
    ): void {
        // Ingest.
        $statusStore->advance($this->conversion_id, ConversionPipelineStage::Ingest);
        $manifest = $extractor->extract($this->url);

        // Plan.
        $statusStore->advance($this->conversion_id, ConversionPipelineStage::Plan);
        $plan = $planner->plan($manifest);

        // IR-pass.
        $statusStore->advance($this->conversion_id, ConversionPipelineStage::IrPass);
        $irPassResult = $irPass->run($plan, $manifest);

        // Hand-off state for FinalizeConversionJob (which runs later, in a
        // different worker, after block-fill's batch completes and
        // reconcile fires). Must be written BEFORE dispatching the batch
        // — Finalize would break if the context isn't there.
        $contextStore->put(
            $this->conversion_id,
            new ConversionContext(
                conversion_id: $this->conversion_id,
                url: $this->url,
                manifest: $manifest,
                plan: $plan,
            ),
        );

        // Block-fill dispatch: schedules the batch + wires
        // finally→ReconcileBlockFillJob. Returns immediately under
        // async queue; blocks under sync.
        $statusStore->advance($this->conversion_id, ConversionPipelineStage::BlockFill);
        $blockFill->dispatch($irPassResult, $plan, $manifest, $this->conversion_id);
    }

    // Load-bearing no-silent-hang hook. Fires when tries are
    // exhausted OR the job's handle() throws uncaught. Writes a
    // Failed status with the exception message so /status can report
    // the failure to the demo watcher immediately.
    public function failed(Throwable $exception): void
    {
        $statusStore = app(ConversionStatusStore::class);
        $statusStore->fail(
            $this->conversion_id,
            $this->classifyFailure($exception),
        );
        // Release concurrency budget even on catastrophic failure —
        // otherwise the demo hits its concurrency cap and rejects the
        // NEXT visitor. Same posture as FinalizeConversionJob's
        // release: cost-guard state can't leak.
        app(ConversionCostGuard::class)
            ->releaseConcurrency();
    }

    private function classifyFailure(Throwable $exception): string
    {
        // Keep the message concise for the demo UI. Full stack goes
        // to Laravel's failed_jobs table via Horizon.
        $message = $exception->getMessage();
        if ($message === '') {
            $message = get_class($exception);
        }

        // Prefix with the stage where the job died, extracted from
        // the exception origin if we can (best-effort — the frontend
        // just needs SOMETHING to show).
        return sprintf('pre-block-fill pipeline threw: %s', $message);
    }
}
