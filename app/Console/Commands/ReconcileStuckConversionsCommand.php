<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FinalizeConversionJob;
use App\Services\Conversion\ConversionResultStore;
use App\Services\Generate\BlockFill;
use App\Services\Generate\BlockFillResultStore;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

// The reconciliation-correctness backstop. Runs every minute (see
// Console\Kernel schedule). Three duties, closing every async
// silent-hang door in the conversion pipeline:
//
//   (a) FINISHED BATCH, CALLBACK NEVER FIRED — the batch's finally()
//       callback (which dispatches ReconcileBlockFillJob) can fail to
//       fire under real production stress: the callback JOB itself
//       OOMs, Redis blips at the wrong moment, its own dispatch fails.
//       Every per-page job wrote its FilledPage / BlockFillFailure,
//       but reconcile never ran. Reconciled-result is absent.
//       Sweeper picks it up on the next 1-min tick.
//
//   (b) BATCH STUCK — pending_jobs never reached zero because a queued
//       job was evicted from Redis (misconfigured maxmemory-policy) or
//       otherwise lost. finished_at is never set. The docker-compose
//       Redis pins maxmemory-policy=noeviction to prevent (b) in the
//       first place, but in production the managed-Redis config is out
//       of our control. Sweeper detects stuck batches older than
//       STUCK_THRESHOLD_MINUTES and reconciles them (surfacing the
//       lost jobs as silently-absent BlockFillFailures).
//
//   (c) RECONCILE SUCCEEDED, FINALIZE NEVER FIRED — the CONVERSION-
//       LEVEL analog of (a). ReconcileBlockFillJob wrote a
//       BlockFillResult, but its subsequent FinalizeConversionJob
//       dispatch failed (or the Finalize job itself OOM'd). At the
//       block-fill level everything's fine; at the conversion level,
//       the /status endpoint would report "block_fill" forever
//       (silent hang from the demo's perspective). Sweeper dispatches
//       Finalize (idempotent) so the conversion completes.
//
// Duties (a) and (b) call BlockFill::reconcile which is idempotent by
// design. Duty (c) dispatches FinalizeConversionJob which is also
// idempotent (returns early if ConversionResult exists). The scheduled
// sweeper's tick can safely overlap with a slower-firing batch
// callback — whichever completes first wins, others are no-ops.
//
// After ANY reconcile in this sweep run, ALSO dispatch Finalize
// (belt-and-braces: reconcile fired, so Finalize should run too;
// dispatching it defensively closes the second-half hang door
// without waiting another minute for the next sweep tick).
final class ReconcileStuckConversionsCommand extends Command
{
    protected $signature = 'engine:reconcile-stuck-conversions';

    protected $description = 'Reconcile any block-fill batch whose callback failed to fire OR whose batch is stuck.';

    // Threshold for classifying a batch as "stuck" (still running past
    // this age). Sized to safely exceed the worst-case LEGITIMATE
    // block-fill wall clock so the sweeper doesn't false-positive-fire
    // on a genuinely-slow batch and surface running pages as
    // "silently absent."
    //
    // Worst-case wall-clock math (per-page Sonnet call × pages ÷ concurrency):
    //   - Typical page (2-5KB body):        ~10-40s Sonnet
    //   - Heavy page (>10KB body):          ~60-120s
    //   - Pathological rulebook / large:    ~180s (up to GeneratePageJob's
    //                                       $timeout=600s, but observed real
    //                                       runs plateau ~180s worst-case)
    //   - Production maxProcesses:          10 (config/horizon.php)
    //
    // Bounding cases:
    //   -  30 pages × 180s /  10 workers =  9 min (large youth-sports site)
    //   - 100 pages × 180s /  10 workers = 30 min (upper realistic bound)
    //   - 100 pages ×  90s /   5 workers = 30 min (mid-tier concurrency)
    //   -  30 pages × 180s /   3 workers = 30 min (local dev worst case)
    //
    // 45 minutes gives 30-min worst-case + 15-min buffer for retries,
    // transient stalls, and reconcile-job dispatch latency. Cost of the
    // buffer: a genuinely-stuck conversion waits up to 45 min for
    // sweeper attention (bounded, still recoverable). Cost of being too
    // tight: a legitimately-slow batch gets false-swept, running pages
    // surface as silently-absent, idempotency freezes the stale result
    // — that's a REGRESSION of the silent-loss guarantee, so we err
    // long. Duty (a) — callback-loss recovery — has NO age requirement;
    // it fires as soon as finished_at is set. Only stuck-batch detection
    // uses this threshold.
    private const STUCK_THRESHOLD_MINUTES = 45;

    public function handle(
        BlockFill $blockFill,
        BlockFillResultStore $store,
        ConversionResultStore $resultStore,
    ): int {
        $reconciled = 0;
        $finalizeDispatched = 0;
        $noOp = 0;
        $failed = 0;

        // Duty (a): finished batches whose callback never fired.
        $finishedButUnreconciled = DB::table('job_batches')
            ->where('name', 'like', 'block-fill:%')
            ->whereNotNull('finished_at')
            ->whereNull('cancelled_at')
            ->select(['id', 'name', 'finished_at'])
            ->get();

        foreach ($finishedButUnreconciled as $row) {
            $conversionId = $this->conversionIdFromBatchName((string) $row->name);
            if ($conversionId === null) {
                continue;
            }

            if ($store->getReconciledResult($conversionId) !== null) {
                // Reconcile already ran, but MIGHT still be stuck at
                // Finalize — fall through to duty (c) check below.
                $noOp++;
            } else {
                try {
                    $blockFill->reconcile($conversionId);
                    $reconciled++;
                    $this->line("finished-batch reconciled: {$conversionId}");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("reconcile failed for {$conversionId}: {$e->getMessage()}");

                    continue;
                }
            }

            // Duty (c) inline: if Finalize hasn't produced a
            // ConversionResult yet, dispatch it. Idempotent — Finalize
            // returns early if ConversionResult already exists. Closes
            // the mid-chain hang (reconcile succeeded, Finalize
            // dispatch failed) even when reconcile has been done a
            // while.
            if ($resultStore->get($conversionId) === null) {
                FinalizeConversionJob::dispatch($conversionId);
                $finalizeDispatched++;
                $this->line("finalize dispatched: {$conversionId}");
            }
        }

        // Duty (b): stuck batches — never finished, past the age threshold.
        $stuckThreshold = Carbon::now()->subMinutes(self::STUCK_THRESHOLD_MINUTES)->timestamp;
        $stuck = DB::table('job_batches')
            ->where('name', 'like', 'block-fill:%')
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->where('created_at', '<', $stuckThreshold)
            ->select(['id', 'name', 'created_at'])
            ->get();

        foreach ($stuck as $row) {
            $conversionId = $this->conversionIdFromBatchName((string) $row->name);
            if ($conversionId === null) {
                continue;
            }

            if ($store->getReconciledResult($conversionId) === null) {
                try {
                    $blockFill->reconcile($conversionId);
                    $reconciled++;
                    $this->warn("stuck-batch reconciled (past {$row->created_at}): {$conversionId}");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("reconcile failed for stuck {$conversionId}: {$e->getMessage()}");

                    continue;
                }
            } else {
                $noOp++;
            }

            if ($resultStore->get($conversionId) === null) {
                FinalizeConversionJob::dispatch($conversionId);
                $finalizeDispatched++;
                $this->warn("stuck-batch finalize dispatched: {$conversionId}");
            }
        }

        if ($reconciled === 0 && $finalizeDispatched === 0 && $noOp === 0 && $failed === 0) {
            $this->line('no batches to sweep');
        } else {
            $this->line(sprintf(
                'swept: reconciled=%d  finalize_dispatched=%d  no-op=%d  failed=%d',
                $reconciled,
                $finalizeDispatched,
                $noOp,
                $failed,
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Batch names are `block-fill:<conversion_id>` per BlockFill::dispatch()
     * — parse the conversion id back out.
     */
    private function conversionIdFromBatchName(string $batchName): ?string
    {
        if (! str_starts_with($batchName, 'block-fill:')) {
            return null;
        }
        $tail = substr($batchName, strlen('block-fill:'));

        return $tail !== '' ? $tail : null;
    }
}
