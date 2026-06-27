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
// $tries = 1 for v1 — no automatic retry. Transient errors land in
// BlockFillFailure with the underlying exception message; a reviewer
// can re-dispatch the conversion. Wiring retries into Horizon is a
// step-6 concern (trigger endpoint + queue wiring).
final class GeneratePageJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

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
            // Terminal failure — record so reconciliation surfaces this
            // page as a BlockFillFailure, NEVER a stub. Catching here
            // (rather than relying on failed()) ensures the batch isn't
            // cancelled by the exception propagating up.
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
}
