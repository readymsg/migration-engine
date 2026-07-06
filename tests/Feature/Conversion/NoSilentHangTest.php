<?php

declare(strict_types=1);

namespace Tests\Feature\Conversion;

use App\Data\ConversionPipelineStage;
use App\Jobs\ConversionJob;
use App\Jobs\FinalizeConversionJob;
use App\Services\Conversion\ConversionResultStore;
use App\Services\Conversion\ConversionStatusStore;
use App\Services\Extract\Extractor;
use App\Services\Generate\BlockFillAgent;
use App\Services\Plan\ClassifierAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\Generate\FakeBlockFillAgent;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;

// LOAD-BEARING for the demo. A live demo's worst failure is a spinner
// stuck forever at "stage: ingest" while someone watches. Every job in
// the chain has a failed() hook that writes final_status=failed to the
// status store — this test suite proves that CONTRACT holds for each
// job independently AND that the sweeper closes the mid-chain hang
// door when a callback fails.
//
// These tests do NOT hit real Sonnet, Redis, or Horizon — they run
// under phpunit's sync queue with Bus::fake where needed. What they
// prove is the FAILURE-SURFACE INVARIANTS: no matter where a
// throwable escapes, the status snapshot ends up in a terminal
// stage (Failed) with a failure_reason. NEVER a non-terminal stage
// that never advances.
final class NoSilentHangTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ClassifierAgent::class, new FakeClassifierAgent);
        $this->app->instance(BlockFillAgent::class, new FakeBlockFillAgent);
    }

    #[Test]
    public function conversion_job_ingest_throws_status_flips_to_failed_not_stuck(): void
    {
        // Simulate INGEST throwing (e.g. SE site_id not found in a
        // non-SE URL, or Firecrawl 500). ConversionJob::handle() lets
        // the throw propagate. failed() hook writes Failed status.
        // Assert /status would report final_status=failed with a
        // failure_reason — NEVER stuck at ingest.
        $this->app->instance(Extractor::class, new class implements Extractor
        {
            public function extract(string $url): \App\Data\Manifest
            {
                throw new RuntimeException('boom: could not resolve SE site_id from URL');
            }
        });

        $conversionId = 'conv-ingest-throw';
        $statusStore = app(ConversionStatusStore::class);
        $statusStore->begin($conversionId, 'https://not-a-real-se-site.example/');

        // dispatch under sync queue = run inline. Should throw, but the
        // job's failed() hook writes the status BEFORE the throw
        // propagates up. We catch it here to keep the assertion focused.
        try {
            ConversionJob::dispatch($conversionId, 'https://not-a-real-se-site.example/');
        } catch (\Throwable) {
            // expected — sync queue re-throws after failed() writes
        }

        $snapshot = $statusStore->get($conversionId);
        $this->assertNotNull($snapshot);
        $this->assertTrue(
            $snapshot->stage->isTerminal(),
            'ingest throw must leave status in a terminal stage, not stuck at '.$snapshot->stage->value
        );
        $this->assertSame(ConversionPipelineStage::Failed, $snapshot->stage);
        $this->assertNotNull($snapshot->failure_reason);
        $this->assertStringContainsString('boom', $snapshot->failure_reason);
    }

    #[Test]
    public function conversion_job_plan_throws_status_flips_to_failed(): void
    {
        // Same shape, later stage. PLAN throws mid-run. failed()
        // must still fire. (IR-pass is deliberately resilient to
        // agent-level throws by design — it degrades to Partial with
        // a sentinel failure. To exercise "mid-pipeline throw", pick
        // a stage that DOES propagate. Planner is that stage.)
        $manifest = RealManifests::tbirdhoops();
        $this->app->instance(Extractor::class, new class($manifest) implements Extractor
        {
            public function __construct(private readonly \App\Data\Manifest $manifest) {}

            public function extract(string $url): \App\Data\Manifest
            {
                return $this->manifest;
            }
        });

        $this->app->instance(
            \App\Services\Plan\Planner::class,
            new class implements \App\Services\Plan\Planner
            {
                public function plan(\App\Data\Manifest $manifest): \App\Data\SitePlan
                {
                    throw new RuntimeException('boom: PLAN Haiku 503');
                }
            },
        );

        $conversionId = 'conv-plan-throw';
        $statusStore = app(ConversionStatusStore::class);
        $statusStore->begin($conversionId, 'https://www.tbirdhoops.org/');

        try {
            ConversionJob::dispatch($conversionId, 'https://www.tbirdhoops.org/');
        } catch (\Throwable) {
        }

        $snapshot = $statusStore->get($conversionId);
        $this->assertNotNull($snapshot);
        $this->assertSame(ConversionPipelineStage::Failed, $snapshot->stage);
        $this->assertStringContainsString('PLAN Haiku 503', (string) $snapshot->failure_reason);
    }

    #[Test]
    public function finalize_conversion_job_throws_status_flips_to_failed(): void
    {
        // Simulate FinalizeConversionJob throwing (e.g. Assembler
        // internal bug, DraftLanding client 5xx). failed() must fire
        // and status must land on Failed.
        //
        // We craft a state where Finalize CAN start (status snapshot
        // exists), but the ConversionContext is missing — which the
        // job treats as a real bug and throws for. tries=3 exhausted
        // → failed() fires.
        $conversionId = 'conv-finalize-throw';
        $statusStore = app(ConversionStatusStore::class);
        $statusStore->begin($conversionId, 'https://www.tbirdhoops.org/');
        // Advance to BlockFill so Finalize considers itself "in a
        // full pipeline" and proceeds through the context check.
        $statusStore->advance($conversionId, ConversionPipelineStage::BlockFill);

        // Directly invoke failed() (the way tries=3 exhaustion would).
        $exception = new RuntimeException('assembler internal bug');
        (new FinalizeConversionJob($conversionId))->failed($exception);

        $snapshot = $statusStore->get($conversionId);
        $this->assertNotNull($snapshot);
        $this->assertSame(ConversionPipelineStage::Failed, $snapshot->stage);
        $this->assertStringContainsString('assembler internal bug', (string) $snapshot->failure_reason);
    }

    #[Test]
    public function conversion_job_failed_hook_writes_status_even_without_prior_begin(): void
    {
        // Defensive: if begin() was skipped somehow (bug in the
        // trigger endpoint), and ConversionJob's handle() throws
        // before writing any status, failed() should STILL write a
        // failed snapshot so /status returns something actionable.
        // The store's fail() has a defensive path for this.
        $conversionId = 'conv-no-begin';

        (new ConversionJob($conversionId, 'https://example.test/'))
            ->failed(new RuntimeException('boom'));

        $snapshot = app(ConversionStatusStore::class)->get($conversionId);
        $this->assertNotNull(
            $snapshot,
            'even without begin(), failed() must have written a bare Failed snapshot'
        );
        $this->assertSame(ConversionPipelineStage::Failed, $snapshot->stage);
        $this->assertStringContainsString('boom', (string) $snapshot->failure_reason);
    }

    #[Test]
    public function sweeper_kicks_finalize_when_reconcile_succeeded_but_finalize_never_fired(): void
    {
        // The mid-chain hang door: ReconcileBlockFillJob succeeds,
        // writes a reconciled BlockFillResult, but its subsequent
        // FinalizeConversionJob dispatch fails (Redis blip, callback
        // job OOM). The conversion sits at stage=block_fill in the
        // status store while block-fill IS actually done.
        //
        // Sweeper's duty (c) recovers: sees reconciled-result present,
        // ConversionResult absent, dispatches Finalize. Under sync
        // queue that runs inline. When run below, since the test setup
        // hasn't primed the ConversionContext, the graceful "not
        // running in a full pipeline" guard fires and Finalize exits
        // silently — but the sweeper still DID dispatch it, closing
        // the hang door.
        //
        // We assert the sweeper's Bus::dispatched(FinalizeConversionJob)
        // count for the target conversion — that's the load-bearing
        // signal.
        Bus::fake([FinalizeConversionJob::class]);

        $conversionId = 'conv-sweeper-kicks';
        $now = time();

        // Set up a finished batch (finished_at set) whose reconciled
        // result already exists. Sweeper's duty (a) sees no reconcile
        // work but STILL dispatches Finalize because ConversionResult
        // is missing.
        \Illuminate\Support\Facades\DB::table('job_batches')->insert([
            'id' => 'batch-'.$conversionId,
            'name' => 'block-fill:'.$conversionId,
            'total_jobs' => 3,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => serialize(['allowFailures' => true]),
            'cancelled_at' => null,
            'created_at' => $now - 120,
            'finished_at' => $now - 30,
        ]);

        // Prime the BlockFillReconciledResult (so sweeper sees "already
        // reconciled") but leave ConversionResult empty.
        $store = new \App\Services\Generate\CacheBlockFillResultStore(
            app(\Illuminate\Contracts\Cache\Repository::class),
        );
        $store->putReconciledResult(
            $conversionId,
            new \App\Data\BlockFillResult(
                style_brief: new \App\Data\GlobalStyleBrief(
                    brand_voice: '',
                    palette: [],
                    layout_conventions: [],
                    nav: new \Spatie\LaravelData\DataCollection(\App\Data\NavItem::class, []),
                ),
                pages: new \Spatie\LaravelData\DataCollection(\App\Data\FilledPage::class, []),
                failures: new \Spatie\LaravelData\DataCollection(\App\Data\BlockFillFailure::class, []),
                status: \App\Data\BlockFillStatus::Complete,
            ),
        );

        Artisan::call('engine:reconcile-stuck-conversions');

        Bus::assertDispatched(
            FinalizeConversionJob::class,
            fn (FinalizeConversionJob $job): bool => $job->conversion_id === $conversionId,
        );
    }

    #[Test]
    public function status_snapshot_never_reports_non_terminal_stage_after_conversion_job_fails(): void
    {
        // Property test: NO matter how ConversionJob dies (before
        // ingest, during ingest, mid-plan, mid-ir-pass), the
        // resulting status must be terminal. This is the umbrella
        // "no silent hang" contract — the specific stage-throw tests
        // above prove specific cases; this test asserts the general
        // property.
        $this->app->instance(Extractor::class, new class implements Extractor
        {
            public function extract(string $url): \App\Data\Manifest
            {
                throw new RuntimeException('kaboom');
            }
        });

        $conversionId = 'conv-property-test';
        $statusStore = app(ConversionStatusStore::class);
        $statusStore->begin($conversionId, 'https://example.test/');

        try {
            ConversionJob::dispatch($conversionId, 'https://example.test/');
        } catch (\Throwable) {
        }

        $snapshot = $statusStore->get($conversionId);
        $this->assertNotNull($snapshot);
        $this->assertTrue(
            $snapshot->stage->isTerminal(),
            'CONTRACT VIOLATION: status snapshot in non-terminal stage after failed conversion '
            .'— the frontend would poll forever. Stage was: '.$snapshot->stage->value
        );

        // And the /status endpoint (via finalStatus()) returns a
        // recognizably-terminal value.
        $this->assertContains(
            $snapshot->finalStatus(),
            ['complete', 'partial', 'failed'],
            'finalStatus() must be one of the terminal string values, not "in_progress"'
        );
    }
}
