<?php

declare(strict_types=1);

namespace Tests\Feature\Conversion;

use App\Data\ConversionPipelineStage;
use App\Data\ConversionStatus;
use App\Data\Manifest;
use App\Jobs\ConversionJob;
use App\Services\Conversion\ConversionResultStore;
use App\Services\Conversion\ConversionStatusStore;
use App\Services\Extract\Extractor;
use App\Services\Generate\Assembler;
use App\Services\Generate\BlockFill;
use App\Services\Generate\BlockFillAgent;
use App\Services\Generate\DraftLanding;
use App\Services\Generate\IrPass;
use App\Services\Generate\PlatformBlockRenderer;
use App\Services\Generate\SePlatformBlockScrubber;
use App\Services\Plan\ClassifierAgent;
use App\Services\Plan\Planner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Generate\FakeBlockFillAgent;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;
use Tests\TestCase;

// LOAD-BEARING correctness gate: prove the chain-as-jobs (ConversionJob
// → batch → ReconcileBlockFillJob → FinalizeConversionJob) produces the
// SAME ConversionResult as the chain-as-inline (CaptureLive-style
// straight-line service calls) given identical inputs.
//
// Runs under QUEUE_CONNECTION=sync (phpunit.xml default) so the entire
// async chain executes inline in the test process — batch runs, batch
// finally fires inline, ReconcileBlockFillJob runs inline, Finalize
// runs inline. Zero LLM cost (FakeBlockFillAgent + FakeClassifierAgent +
// fixture-based extractor).
//
// This is the CORRECTNESS PROOF the step-6 slice needed before wiring
// the endpoint: if chain-as-jobs != chain-as-inline, something in the
// hand-off between jobs is losing state, and no HTTP layer is safe.
final class ChainEqualsInlineTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed>|null */
    private ?array $inlineResultAsArray = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic + free. FakeClassifierAgent = Keep@0.85, no
        // Haiku call. FakeBlockFillAgent = stub FilledPages, no Sonnet.
        // Both are already the "sync path" tests use everywhere else.
        $this->app->instance(ClassifierAgent::class, new FakeClassifierAgent);
        $this->app->instance(BlockFillAgent::class, new FakeBlockFillAgent);

        // Inline fake extractor — returns the pre-baked tbirdhoops
        // Manifest from RealManifests. Both the inline run and the
        // chain run resolve THIS instance, so INGEST produces the same
        // Manifest in both paths (byte-for-byte).
        $manifest = RealManifests::tbirdhoops();
        $this->app->instance(Extractor::class, new class($manifest) implements Extractor
        {
            public function __construct(private readonly Manifest $manifest) {}

            public function extract(string $url): Manifest
            {
                return $this->manifest;
            }
        });
    }

    #[Test]
    public function chain_as_jobs_equals_chain_as_inline_for_tbirdhoops(): void
    {
        // ── INLINE run: same shape as CaptureLive, straight-line
        //    service calls, no jobs at all. Ground truth. ────────────
        $manifest = RealManifests::tbirdhoops();
        $plan = app(Planner::class)->plan($manifest);
        $irPass = app(IrPass::class)->run($plan, $manifest);

        $conversionIdInline = 'conv-inline-test';
        // BlockFill::run under sync queue: dispatch + finally + reconcile
        // fire inline in this process. We use ->run() (the sync wrapper).
        $blockFillResult = app(BlockFill::class)->run($irPass, $plan, $manifest, $conversionIdInline);
        $assembly = app(Assembler::class)->run($blockFillResult);
        $assembly = app(SePlatformBlockScrubber::class)->run($assembly);
        $platform = app(PlatformBlockRenderer::class)->run($plan, $manifest);
        $inlineConversion = app(DraftLanding::class)->run(
            conversionId: $conversionIdInline,
            plan: $plan,
            assembly: $assembly,
            platform: $platform,
            manifest: $manifest,
        );

        // ── CHAIN run: dispatch ConversionJob under sync queue. All
        //    downstream (batch → finally → ReconcileBlockFillJob →
        //    FinalizeConversionJob) fires inline. Read the result out
        //    of the ConversionResultStore where FinalizeConversionJob
        //    wrote it. ──────────────────────────────────────────────
        $conversionIdChain = 'conv-chain-test';
        $statusStore = app(ConversionStatusStore::class);
        $statusStore->begin($conversionIdChain, 'https://www.tbirdhoops.org/');

        ConversionJob::dispatch($conversionIdChain, 'https://www.tbirdhoops.org/');

        $chainConversion = app(ConversionResultStore::class)->get($conversionIdChain);
        $this->assertNotNull(
            $chainConversion,
            'chain-as-jobs must have written a ConversionResult by the time dispatch returns under sync queue'
        );

        // Terminal status too — this is what the /status endpoint would report.
        $snapshot = $statusStore->get($conversionIdChain);
        $this->assertNotNull($snapshot);
        $this->assertTrue(
            $snapshot->stage->isTerminal(),
            'chain-as-jobs must have advanced status to a terminal stage; found: '.$snapshot->stage->value
        );
        $this->assertSame(
            match ($chainConversion->status) {
                ConversionStatus::Completed => ConversionPipelineStage::Complete,
                ConversionStatus::Partial => ConversionPipelineStage::Partial,
                ConversionStatus::Failed => ConversionPipelineStage::Failed,
            },
            $snapshot->stage,
            'status snapshot must reflect the ConversionResult status'
        );

        // ── EQUALITY: byte-for-byte modulo conversion_id + org_id (which
        //    differ by design between the two runs — the id is per-run).
        //    Everything else must match: page_map, nav, failures,
        //    block_issues, scrub_issues, status, brand, style_brief. ───
        $inlineArr = $inlineConversion->toArray();
        $chainArr = $chainConversion->toArray();

        // Normalize conversion_id fields — they're per-run and expected to differ.
        $inlineArr['conversion_id'] = 'CONV_ID';
        $chainArr['conversion_id'] = 'CONV_ID';

        // draft_id + draft_url from RecordingProductClient/StubProductClient
        // may include the conversion_id in their strings; normalize too.
        // (Under DraftLanding's stub, draft_id is deterministic per conversion.
        // Different conversion_ids → different draft strings. Not a real
        // divergence.)
        $inlineArr['draft_id'] = 'DRAFT_ID';
        $chainArr['draft_id'] = 'DRAFT_ID';
        $inlineArr['draft_url'] = 'DRAFT_URL';
        $chainArr['draft_url'] = 'DRAFT_URL';

        $this->assertSame(
            $inlineArr,
            $chainArr,
            'chain-as-jobs must produce the same ConversionResult as chain-as-inline '
            .'(modulo conversion_id + draft fields). Any divergence = broken hand-off '
            .'between ConversionJob and FinalizeConversionJob.'
        );

        // Final belt-and-braces: the stage transitions the chain made
        // should be observable via block-fill progress computation the
        // status endpoint would use. Not asserted here (implementation-
        // detail) but the terminal-stage assertion above covers the end
        // state.
    }
}
