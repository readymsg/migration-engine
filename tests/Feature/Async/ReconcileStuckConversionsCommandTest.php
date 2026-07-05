<?php

declare(strict_types=1);

namespace Tests\Feature\Async;

use App\Data\BlockFillFailure;
use App\Data\BlockFillReconcileState;
use App\Data\FilledBlock;
use App\Data\FilledPage;
use App\Data\GlobalStyleBrief;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Data\IrPassFailure;
use App\Data\IrPassResult;
use App\Data\IrPassStatus;
use App\Data\NavItem;
use App\Services\Generate\CacheBlockFillResultStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Sweeper duty coverage. Two paired assertions:
//
//   (a) FINISHED batch, callback never fired: sweeper sees the row in
//       job_batches with finished_at set, notices reconciled-result is
//       absent, invokes reconcile.
//   (b) STUCK batch: sweeper sees the row with finished_at NULL and
//       created_at older than STUCK_THRESHOLD_MINUTES, invokes reconcile
//       (which surfaces the never-completed pages as silently-absent).
//
// Plus:
//   (c) Sweeper is idempotent: running it twice back-to-back doesn't
//       change the reconciled result. Guards the 1-min cadence.
//   (d) In-flight batches (finished_at NULL, created_at recent) are
//       LEFT ALONE. The sweeper doesn't prematurely reconcile a batch
//       that's just still running.
final class ReconcileStuckConversionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(): CacheBlockFillResultStore
    {
        return new CacheBlockFillResultStore($this->app->make(Repository::class));
    }

    private function insertBatch(string $conversionId, ?int $finishedAt, int $createdAt): void
    {
        // Minimum fields to satisfy the DatabaseBatchRepository schema.
        // We're not exercising the real batch machinery here — just
        // creating rows the sweeper's query filters on.
        DB::table('job_batches')->insert([
            'id' => 'batch-'.$conversionId,
            'name' => 'block-fill:'.$conversionId,
            'total_jobs' => 3,
            'pending_jobs' => $finishedAt === null ? 3 : 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => serialize(['allowFailures' => true]),
            'cancelled_at' => null,
            'created_at' => $createdAt,
            'finished_at' => $finishedAt,
        ]);
    }

    /**
     * Write a bare-minimum reconcile-state to the store so
     * BlockFill::reconcile() can execute (won't throw the "no reconcile
     * state" error). Content is irrelevant for the sweeper's routing
     * logic; the DTO round-trip is enough.
     */
    private function primeReconcileState(string $conversionId, int $expectedSlugCount = 3): void
    {
        $store = $this->makeStore();

        $slugs = [];
        for ($i = 1; $i <= $expectedSlugCount; $i++) {
            $slugs[] = "page-{$i}";
        }

        $store->putReconcileState(
            $conversionId,
            new BlockFillReconcileState(
                conversion_id: $conversionId,
                ir_pass: new IrPassResult(
                    style_brief: new GlobalStyleBrief(
                        brand_voice: '',
                        palette: [],
                        layout_conventions: [],
                        nav: new DataCollection(NavItem::class, []),
                    ),
                    pages: new DataCollection(Ir::class, array_map(static fn (string $slug, int $i): Ir => new Ir(
                        page_slug: $slug,
                        page_title: $slug,
                        nav_order: $i,
                        blocks: new DataCollection(IrBlock::class, [
                            new IrBlock(component_type: 'heading', content_brief: 'stub'),
                        ]),
                    ), $slugs, array_keys($slugs))),
                    failures: new DataCollection(IrPassFailure::class, []),
                    status: IrPassStatus::Complete,
                ),
                preflight_failures: new DataCollection(BlockFillFailure::class, []),
                expected_slugs: $slugs,
            ),
        );
    }

    private function stubFilledPage(string $slug): FilledPage
    {
        return new FilledPage(
            page_slug: $slug,
            page_title: $slug,
            nav_order: 0,
            blocks: new DataCollection(FilledBlock::class, [
                new FilledBlock(
                    component_type: 'Text',
                    props: ['body' => 'stub'],
                    source_brief: 'stub',
                    source_quote: 'stub',
                ),
            ]),
            self_assessment: 'stub',
            confidence: 1.0,
        );
    }

    #[Test]
    public function duty_a_finished_batch_with_missing_reconciled_result_is_reconciled(): void
    {
        // Batch finished 30 seconds ago (finished_at set), all 3 workers
        // wrote their results, but ReconcileBlockFillJob never fired
        // (callback loss). Reconciled-result is absent.
        $conversionId = 'conv-duty-a';
        $store = $this->makeStore();
        $now = time();

        $this->insertBatch($conversionId, finishedAt: $now - 30, createdAt: $now - 120);
        $this->primeReconcileState($conversionId);
        $store->putFilledPage($conversionId, $this->stubFilledPage('page-1'));
        $store->putFilledPage($conversionId, $this->stubFilledPage('page-2'));
        $store->putFilledPage($conversionId, $this->stubFilledPage('page-3'));

        $this->assertNull($store->getReconciledResult($conversionId), 'precondition');

        Artisan::call('engine:reconcile-stuck-conversions');

        $reconciled = $store->getReconciledResult($conversionId);
        $this->assertNotNull($reconciled, 'sweeper must have reconciled this batch');
        $this->assertSame(3, $reconciled->pages->count(), 'all 3 FilledPages recovered');
        $this->assertSame(0, $reconciled->failures->count());
    }

    #[Test]
    public function duty_b_stuck_batch_past_threshold_is_reconciled_with_silently_absent(): void
    {
        // Batch created 50 minutes ago, never finished (finished_at NULL)
        // — under noeviction Redis this shouldn't happen, but production
        // Redis config is not always under our control. Sweeper picks up
        // the stuck batch past the 45-minute threshold and reconciles it
        // (surfacing the never-completed pages as silently-absent).
        //
        // 45 min threshold sized to safely exceed worst-case legitimate
        // block-fill wall-clock (100 pages × 180s / 10 concurrency =
        // 30 min + 15 min buffer). See STUCK_THRESHOLD_MINUTES docblock.
        $conversionId = 'conv-duty-b';
        $store = $this->makeStore();
        $now = time();

        $this->insertBatch($conversionId, finishedAt: null, createdAt: $now - (50 * 60));
        $this->primeReconcileState($conversionId);
        // Only 1 of 3 pages ever wrote a result before the stuck event.
        $store->putFilledPage($conversionId, $this->stubFilledPage('page-1'));

        Artisan::call('engine:reconcile-stuck-conversions');

        $reconciled = $store->getReconciledResult($conversionId);
        $this->assertNotNull($reconciled, 'sweeper must have reconciled the stuck batch');
        $this->assertSame(1, $reconciled->pages->count(), 'only page-1 was in the store when swept');
        $this->assertSame(2, $reconciled->failures->count(), 'page-2 and page-3 silently absent');
    }

    #[Test]
    public function in_flight_batch_recent_and_unfinished_is_left_alone(): void
    {
        // Batch created 25 minutes ago, finished_at NULL (still running).
        // Well inside the 45-min threshold — sweeper MUST NOT
        // prematurely reconcile this or we introduce false-negatives on
        // pages that are just still processing. 25 min covers a
        // legitimate large batch: 100 pages × 180s / 10 workers ≈ 30 min.
        $conversionId = 'conv-in-flight';
        $store = $this->makeStore();
        $now = time();

        $this->insertBatch($conversionId, finishedAt: null, createdAt: $now - (25 * 60));
        $this->primeReconcileState($conversionId);

        Artisan::call('engine:reconcile-stuck-conversions');

        $this->assertNull(
            $store->getReconciledResult($conversionId),
            'in-flight batch must NOT be reconciled prematurely — that would replace '
            .'not-yet-arrived FilledPages with silently-absent, then idempotency freezes the '
            .'stale result and later worker writes are lost'
        );
    }

    #[Test]
    public function sweeper_is_idempotent_second_tick_no_ops_on_already_reconciled_batches(): void
    {
        // Two sweeper ticks in a row: the first reconciles, the second
        // sees the reconciled-result and no-ops. Overlapping ticks
        // (withoutOverlapping guards prevent this in production but it
        // can still race across processes) must be safe.
        $conversionId = 'conv-idempotent';
        $store = $this->makeStore();
        $now = time();

        $this->insertBatch($conversionId, finishedAt: $now - 30, createdAt: $now - 60);
        $this->primeReconcileState($conversionId);
        $store->putFilledPage($conversionId, $this->stubFilledPage('page-1'));
        $store->putFilledPage($conversionId, $this->stubFilledPage('page-2'));
        $store->putFilledPage($conversionId, $this->stubFilledPage('page-3'));

        Artisan::call('engine:reconcile-stuck-conversions');
        $first = $store->getReconciledResult($conversionId);
        $this->assertNotNull($first);

        // Now simulate a fourth "late" FilledPage arriving after
        // reconcile. Idempotency guard means this is ignored — the
        // reconciled result stays frozen.
        $store->putFilledPage($conversionId, $this->stubFilledPage('page-late'));

        Artisan::call('engine:reconcile-stuck-conversions');
        $second = $store->getReconciledResult($conversionId);
        $this->assertSame(
            $first->pages->count(),
            $second->pages->count(),
            'second sweeper tick must not have re-reconciled — idempotency guard held'
        );
    }

    #[Test]
    public function batch_with_non_block_fill_name_is_ignored(): void
    {
        // Sweeper is scoped to block-fill batches only. A random other
        // batch (e.g., some other engine feature's fan-out) must not be
        // picked up.
        $now = time();
        DB::table('job_batches')->insert([
            'id' => 'batch-other',
            'name' => 'some-other-feature:xyz',
            'total_jobs' => 1,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => serialize([]),
            'cancelled_at' => null,
            'created_at' => $now - 60,
            'finished_at' => $now - 30,
        ]);

        // No reconcile-state — the sweeper would call reconcile() and
        // throw if it processed this row. Verify it doesn't.
        $exitCode = Artisan::call('engine:reconcile-stuck-conversions');
        $this->assertSame(0, $exitCode);
    }
}
