<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\BlockFillFailure;
use App\Data\BlockFillInput;
use App\Data\ContentRef;
use App\Data\Ir;
use App\Services\Generate\BlockFillAgent;
use App\Services\Generate\BlockFillContextStore;
use App\Services\Generate\BlockFillResultStore;
use App\Services\Generate\ContentLoader;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

// Per-page fan-out job for GENERATE stage 3 slice 2c (block-fill).
//
// THIN PAYLOAD: only the conversion id, page slug, the page's Ir, and the
// ContentRef travel in the queue row. The GlobalStyleBrief and the body
// markdown stay OUT of the payload — brief is fetched from
// BlockFillContextStore (per-conversion side store), body is re-resolved
// via ContentLoader. This keeps queue rows small even for sites with
// 20+ pages.
//
// FAITHFUL-REBUILD GUARANTEE: every dispatched job WRITES exactly one
// entry to BlockFillResultStore — either a FilledPage on success OR a
// BlockFillFailure on terminal error. handle() catches Throwable so a
// page-level failure never propagates up and cancels the batch. The
// orchestrator's reconciliation step is the authority — Bus::batch's
// success flag is not.
//
// Retry policy: $tries = 3 with staggered backoff. Targets the exact
// cjfl page-1254223 loss shape — "silently absent from result store
// after batch (job never wrote)" — which happens when the process is
// killed OUTSIDE handle()'s try/catch: worker OOM, worker-timeout
// SIGKILL, Redis eviction of a queued job. Those don't reach the
// catch inside handle() — they kill the process before or during it.
// Laravel's queue-level retry (job not acked → returned to queue by
// the driver) is what survives them.
//
// The catch inside handle() is KEPT for Sonnet-reachable exceptions
// (malformed structured output, agent throw). Those write the
// BlockFillFailure directly and return normally, so Laravel considers
// the job complete — no retry burned. Cost of retrying a genuinely
// terminal Sonnet exception would be ~$0.15 × 2 additional attempts
// per broken page; the catch keeps that budget bounded.
//
// failed() is the safety net: it fires when Laravel exhausts $tries
// without handle() completing — i.e., every attempt process-killed.
// It writes the BlockFillFailure so reconciliation still sees a
// visible failure, never a silent absence.
final class GeneratePageJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    // Backoff between attempts (s): 30s then 60s. Longer than the
    // block-fill call duration itself (~30-90s per BUILD.md) so a
    // slow Sonnet response doesn't cause consecutive attempts to
    // stack on the same worker's timeout window.
    /** @var array<int, int> */
    public array $backoff = [30, 60];

    // Generous job timeout matches AnthropicBlockFillAgent's HTTP timeout.
    // Sonnet 4.6 + structured output + a long body + revision pass can
    // run a couple of minutes; budget for it.
    public int $timeout = 600;

    public function __construct(
        public readonly string $conversion_id,
        public readonly string $page_slug,
        public readonly Ir $ir,
        public readonly ContentRef $content_ref,
        public readonly string $org_id,
    ) {}

    public function handle(
        BlockFillAgent $agent,
        ContentLoader $contentLoader,
        BlockFillContextStore $contextStore,
        BlockFillResultStore $resultStore,
    ): void {
        try {
            $loaded = $contentLoader->load($this->content_ref);
            if ($loaded === null) {
                // The body went missing between orchestrator pre-flight
                // and job execution — should be rare (the orchestrator
                // checks before dispatch) but handle it loudly.
                $resultStore->putFailure(
                    $this->conversion_id,
                    new BlockFillFailure(
                        page_slug: $this->page_slug,
                        page_title: $this->ir->page_title,
                        page_node_id: null,
                        reason: 'content_ref no longer resolves to a readable body at job execution time',
                    ),
                );

                return;
            }

            $brief = $contextStore->get($this->conversion_id);

            $filled = $agent->run(new BlockFillInput(
                org_id: $this->org_id,
                page_slug: $this->page_slug,
                ir: $this->ir,
                style_brief: $brief,
                body_markdown: $loaded->markdown,
                body_image_urls: $loaded->image_urls,
            ));

            $resultStore->putFilledPage($this->conversion_id, $filled);
        } catch (Throwable $e) {
            // Terminal Sonnet-reachable failure — record and return
            // NORMALLY so Bus::batch's sync-queue path doesn't
            // propagate (allowFailures() suppresses the RECORDED
            // batch failure counter, not the sync exception itself).
            // Under async, this ALSO prevents unnecessary retries
            // for exceptions that Sonnet reproducibly threw on
            // attempt 1 — the cost of retrying a malformed-response
            // is real budget and the response won't change.
            $resultStore->putFailure(
                $this->conversion_id,
                new BlockFillFailure(
                    page_slug: $this->page_slug,
                    page_title: $this->ir->page_title,
                    page_node_id: null,
                    reason: 'block-fill failed: '.$e->getMessage(),
                ),
            );
        }
    }

    /**
     * Safety net for process-kill retries. Fires when Laravel's
     * queue driver retries the job $tries times WITHOUT handle()
     * completing — worker OOM, timeout SIGKILL, Redis eviction.
     * The catch inside handle() would have written a failure if
     * the exception was Sonnet-reachable; failed() covers the
     * exceptions that killed the process before handle() finished.
     *
     * Container-resolved because Laravel's failed() hook doesn't
     * do constructor-DI the way handle() does.
     */
    public function failed(?Throwable $e): void
    {
        $resultStore = app(BlockFillResultStore::class);
        $reason = $e !== null
            ? sprintf('block-fill failed after %d attempts: %s', $this->tries, $e->getMessage())
            : sprintf('block-fill failed after %d attempts (no exception recorded — worker likely killed mid-job)', $this->tries);
        $resultStore->putFailure(
            $this->conversion_id,
            new BlockFillFailure(
                page_slug: $this->page_slug,
                page_title: $this->ir->page_title,
                page_node_id: null,
                reason: $reason,
            ),
        );
    }
}
