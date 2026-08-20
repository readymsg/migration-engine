<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\ConversionPipelineStage;
use App\Data\ConversionStatus;
use App\Services\Conversion\ConversionContextStore;
use App\Services\Conversion\ConversionCostGuard;
use App\Services\Conversion\ConversionResultStore;
use App\Services\Conversion\ConversionStatusStore;
use App\Services\Coverage\PageMarkdownLoader;
use App\Services\Generate\Assembler;
use App\Services\Generate\AssetUrlRewriter;
use App\Services\Generate\BlockFillResultStore;
use App\Services\Generate\ContentLoader;
use App\Services\Generate\DraftLanding;
use App\Services\Generate\GalleryFiller;
use App\Services\Generate\HeroImageResolver;
use App\Services\Generate\PlatformBlockRenderer;
use App\Services\Generate\SePlatformBlockScrubber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

// The step-6 post-batch (post-reconcile) stages of the conversion
// pipeline. Dispatched from ReconcileBlockFillJob AFTER
// BlockFill::reconcile() has written the reconciled BlockFillResult
// to cache. Runs Assemble → Scrub → PlatformRender → DraftLand
// INLINE (all deterministic + ms-scale), writes the final
// ConversionResult, updates status to Complete / Partial / Failed.
//
// IDEMPOTENT — if the ConversionResult already exists in the store
// (the sweeper's Finalize-kick recovery, or a duplicate dispatch),
// this job returns early without re-running the deterministic
// pipeline. Protects against double-work.
//
// FAILURE POSTURE (same load-bearing no-silent-hang contract as
// ConversionJob):
//
//   1. failed() writes a Failed status with the exception message.
//   2. tries=3 (not 1): Finalize is deterministic + idempotent, so
//      a transient blip (Redis, DB, cache) shouldn't fail an entire
//      conversion. Same posture as ReconcileBlockFillJob's tries=3.
//   3. If the ConversionContext is missing from the store,
//      Finalize throws — the sweeper cannot recover from a lost
//      context (the manifest + plan are gone). failed() writes the
//      reason so the demo sees "conversion state lost — try again"
//      rather than hanging.
final class FinalizeConversionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly string $conversion_id,
    ) {}

    public function handle(
        ConversionContextStore $contextStore,
        ConversionResultStore $resultStore,
        ConversionStatusStore $statusStore,
        BlockFillResultStore $blockFillResultStore,
        Assembler $assembler,
        SePlatformBlockScrubber $scrubber,
        GalleryFiller $galleryFiller,
        HeroImageResolver $heroResolver,
        AssetUrlRewriter $assetRewriter,
        PageMarkdownLoader $mdLoader,
        ContentLoader $contentLoader,
        PlatformBlockRenderer $platformRenderer,
        DraftLanding $draftLanding,
    ): void {
        // Idempotency guard — if already finalized, no-op. The
        // sweeper's Finalize-kick recovery relies on this.
        if ($resultStore->get($this->conversion_id) !== null) {
            return;
        }

        // "Not running under a full conversion pipeline" gate. When
        // BlockFill::run/dispatch is invoked outside the ConversionJob
        // wrapper (CaptureLive diagnostic, chain-equals-inline test,
        // any direct BlockFill call), no ConversionStatusSnapshot
        // exists — begin() is only called by the endpoint. In that
        // case, Finalize gracefully returns: the caller is doing its
        // own inline assembler/scrubber/etc. downstream, and running
        // ours would either duplicate work or fail on missing context.
        //
        // If a snapshot DOES exist but context doesn't, THAT's a real
        // bug (some part of the pipeline set up status but not
        // context). Throw so the sweeper's tries=3 retry can flag it
        // for a reviewer.
        $snapshot = $statusStore->get($this->conversion_id);
        if ($snapshot === null) {
            return;
        }

        // Advance status to Finalize. The polling frontend sees
        // "Assembling the draft" immediately.
        $statusStore->advance($this->conversion_id, ConversionPipelineStage::Finalize);

        $context = $contextStore->get($this->conversion_id);
        if ($context === null) {
            throw new RuntimeException(
                "FinalizeConversionJob: no ConversionContext for '{$this->conversion_id}' "
                .'— manifest + plan are missing. Cannot finalize without them. '
                .'Re-run the conversion.'
            );
        }

        $blockFillResult = $blockFillResultStore->getReconciledResult($this->conversion_id);
        if ($blockFillResult === null) {
            throw new RuntimeException(
                "FinalizeConversionJob: no reconciled BlockFillResult for '{$this->conversion_id}' "
                .'— block-fill reconcile never wrote its result. '
                .'Should have been surfaced by ReconcileBlockFillJob or the sweeper first.'
            );
        }

        // Deterministic downstream pipeline. Each stage produces a
        // Data DTO consumed by the next; total wall-clock is ms-scale
        // (no LLM, no network).
        $assembly = $assembler->run($blockFillResult);
        $assembly = $scrubber->run($assembly);
        // Deterministic gallery back-fill against the real scrapes on
        // disk (via Manifest.content_refs + ContentLoader). Repairs
        // block-fill's silent gallery truncation; missing gallery
        // targets become visible ScrubKind::GalleryFillFailure entries.
        // No-op when content_refs is empty (offline paths).
        $slugToMd = $mdLoader->fromManifest($context->manifest, $context->plan, $contentLoader);
        $assembly = $galleryFiller->run($assembly, $slugToMd);
        // Deliberate hero-image resolver. MUST run before
        // AssetUrlRewriter so it can inspect the original cdn*.
        // sportngin.com URL paths for banner-shape hints (rewriter
        // replaces those with S3 keys).
        $assembly = $heroResolver->run($assembly, $slugToMd);
        // Deterministic SE-CDN URL rewrite — swap every live cdn*
        // .sportngin.com URL in the assembled Puck for its rehosted S3
        // key using Manifest.asset_refs as the URL→S3 map. Any URL
        // without a matching AssetRef stays live AND is recorded as a
        // visible ScrubKind::AssetRehostMissing so the rebuilt-site
        // "zero live SE dependency" invariant is never silently broken.
        $assembly = $assetRewriter->run($assembly, $context->manifest);
        $platform = $platformRenderer->run($context->plan, $context->manifest);

        $conversion = $draftLanding->run(
            conversionId: $this->conversion_id,
            plan: $context->plan,
            assembly: $assembly,
            platform: $platform,
            manifest: $context->manifest,
        );

        // Persist the final result. Doubles as the idempotency
        // marker (see the guard at top of handle()).
        $resultStore->put($this->conversion_id, $conversion);

        // Terminal status derived from the ConversionResult's own
        // status field.
        $terminalStage = match ($conversion->status) {
            ConversionStatus::Completed => ConversionPipelineStage::Complete,
            ConversionStatus::Partial => ConversionPipelineStage::Partial,
            ConversionStatus::Failed => ConversionPipelineStage::Failed,
        };

        if ($terminalStage === ConversionPipelineStage::Failed) {
            $statusStore->fail(
                $this->conversion_id,
                'downstream pipeline produced a Failed ConversionResult (upstream block-fill or IR-pass was catastrophic)',
            );
        } else {
            $statusStore->complete($this->conversion_id, $terminalStage);
        }

        Log::info('ConversionJob finalized', [
            'conversion_id' => $this->conversion_id,
            'status' => $conversion->status->value,
            'page_map_count' => count($conversion->page_map),
            'failures' => $conversion->failures->count(),
            'draft_id' => $conversion->draft_id,
        ]);

        // Release the concurrency budget so the next visitor's
        // conversion can start. Daily spend counter is NOT decremented
        // — spent is spent. Idempotent (safe if handle() runs twice
        // via retry).
        app(ConversionCostGuard::class)
            ->releaseConcurrency();
    }

    public function failed(Throwable $exception): void
    {
        $statusStore = app(ConversionStatusStore::class);
        $message = $exception->getMessage();
        if ($message === '') {
            $message = get_class($exception);
        }
        $statusStore->fail(
            $this->conversion_id,
            'finalize (post-block-fill) threw: '.$message,
        );
        // Same release posture as ConversionJob::failed — no leaks.
        app(ConversionCostGuard::class)
            ->releaseConcurrency();
    }
}
