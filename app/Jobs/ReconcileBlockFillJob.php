<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Generate\BlockFill;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Callback that runs after Bus::batch(GeneratePageJob) completes. Its ONLY
// job is to invoke BlockFill::reconcile($conversionId) — the reconciliation
// logic itself is unchanged from the sync-era code; this job simply moves
// the CALL to a worker process so it happens after all per-page jobs have
// written their results to the store.
//
// Wired from BlockFill::dispatch() as Bus::batch(...)->finally(fn (Batch $b)
// => ReconcileBlockFillJob::dispatch($conversionId)). Uses finally (not
// then) so it fires even when the batch has failed jobs — a Partial
// conversion still needs reconcile to run so the failures land in a
// visible BlockFillResult.
//
// If THIS job itself fails (worker OOM, uncaught throw, Redis blip mid-
// dispatch), the scheduled sweeper (engine:reconcile-stuck-conversions,
// runs every minute) is the correctness backstop — it re-invokes reconcile
// for any conversion whose reconcile state exists but whose reconciled
// result doesn't. Idempotent by design.
//
// tries=3 here (unlike GeneratePageJob's tries=1) because reconcile is
// cheap, idempotent, and losing it strands the conversion; the retry cost
// is trivial vs the loss cost.
final class ReconcileBlockFillJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $conversion_id,
    ) {}

    public function handle(BlockFill $blockFill): void
    {
        $blockFill->reconcile($this->conversion_id);

        // Chain forward to Finalize. FinalizeConversionJob is
        // idempotent (early-return when ConversionResult already
        // exists) so the sweeper's Finalize-kick recovery + this
        // dispatch can both fire safely for the same conversion —
        // whichever runs first wins.
        FinalizeConversionJob::dispatch($this->conversion_id);
    }
}
