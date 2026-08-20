<?php

declare(strict_types=1);

namespace Tests\Feature\Async;

use App\Data\AssetRef;
use App\Data\BlockFillFailure;
use App\Data\BlockFillStatus;
use App\Data\Brand;
use App\Data\ContentRef;
use App\Data\DecisionEntry;
use App\Data\DecisionLedger;
use App\Data\FilledBlock;
use App\Data\FilledPage;
use App\Data\GlobalStyleBrief;
use App\Data\InventoryPage;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Data\IrPassFailure;
use App\Data\IrPassResult;
use App\Data\IrPassStatus;
use App\Data\Manifest;
use App\Data\NavItem;
use App\Data\NavNode;
use App\Data\SitePlan;
use App\Data\SiteStructure;
use App\Services\Generate\BlockFill;
use App\Services\Generate\BlockFillAgent;
use App\Services\Generate\CacheBlockFillContextStore;
use App\Services\Generate\CacheBlockFillResultStore;
use App\Services\Generate\ContentLoader;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Generate\FakeBlockFillAgent;
use Tests\TestCase;

// Chaos LOGIC tests — proves the reconciliation surface catches each of
// the four silent-loss doors async opens. Under phpunit these run against
// the in-process cache with Bus::fake for dispatch interception; the
// paired artisan commands (engine:async-chaos-*) exercise the same
// scenarios against real Redis + Horizon workers when the user runs the
// docker-compose'd Redis and starts a worker.
//
// Four doors:
//   (1) Worker OOM / SIGKILL mid-job. Job dies BEFORE its try/catch can
//       write a BlockFillFailure. Neither FilledPage nor BlockFillFailure
//       land in the store for that slug. Reconciliation MUST surface
//       "silently absent" for that slug.
//   (2) Worker-level timeout (previously 60s in horizon.php) SIGKILLs a
//       long-running Sonnet call before it completes. Same shape as (1)
//       — process dies mid-flight. Config fix (timeout: 600) prevents
//       real Sonnet calls hitting this; the test proves that IF the
//       timeout DID fire, reconciliation catches it visibly.
//   (3) Batch callback (ReconcileBlockFillJob) fails to fire. The
//       reconcile-state exists but no reconciled-result. The scheduled
//       sweeper is the ONLY recovery.
//   (4) Redis eviction / job deletion — the queued job disappears before
//       execution. Neither pending_jobs decrements nor the job runs. The
//       scheduled sweeper is the ONLY recovery.
//
// Each test manipulates the store directly to simulate the failure
// (Bus::fake means real jobs never run — we simulate their outcomes)
// and asserts the reconciliation surface handles it.
final class AsyncBlockFillChaosTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'async-chaos';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(self::DISK);
        $this->app->instance(ContentLoader::class, new ContentLoader(disk: self::DISK));
    }

    private function makeBlockFill(): BlockFill
    {
        $cache = $this->app->make(Repository::class);

        return new BlockFill(
            new CacheBlockFillContextStore($cache),
            new CacheBlockFillResultStore($cache),
        );
    }

    private function makeStore(): CacheBlockFillResultStore
    {
        return new CacheBlockFillResultStore($this->app->make(Repository::class));
    }

    /**
     * Dispatch a 3-page conversion under Bus::fake so no jobs run.
     * Returns [conversionId, store] so each chaos test can manipulate
     * the store to simulate a specific failure mode.
     */
    private function primeConversation(string $conversionId): CacheBlockFillResultStore
    {
        Bus::fake();
        $this->app->instance(BlockFillAgent::class, new FakeBlockFillAgent);
        [$irPass, $plan, $manifest] = $this->stubIr(3);

        $this->makeBlockFill()->dispatch($irPass, $plan, $manifest, $conversionId);

        return $this->makeStore();
    }

    #[Test]
    public function chaos_worker_sigkill_killed_slug_surfaces_as_silently_absent(): void
    {
        // Chaos scenario: 3 jobs dispatched, worker A processes page-1
        // cleanly, worker B is SIGKILLed while processing page-2 (never
        // writes anything), worker C processes page-3 cleanly.
        //
        // The killed slug (page-2) leaves NO FilledPage AND NO
        // BlockFillFailure in the store. Reconciliation must surface it
        // as "silently absent" — the load-bearing catch that prevents
        // async silent loss.
        $store = $this->primeConversation('conv-sigkill');

        // Simulate worker A and C writing their FilledPages. Worker B
        // (page-2) writes NOTHING — it was SIGKILLed.
        $store->putFilledPage('conv-sigkill', $this->stubFilledPage('page-1'));
        $store->putFilledPage('conv-sigkill', $this->stubFilledPage('page-3'));

        // The batch's finally fires (Horizon's job_batches counter
        // decrements even for failed jobs, and allowFailures means one
        // dead job doesn't cancel the batch). ReconcileBlockFillJob
        // runs, invoking reconcile().
        $result = $this->makeBlockFill()->reconcile('conv-sigkill');

        $this->assertSame(BlockFillStatus::Partial, $result->status);
        $this->assertSame(2, $result->pages->count(), 'page-1 and page-3 succeeded');
        $this->assertSame(1, $result->failures->count(), 'page-2 surfaces as ONE silently-absent failure');

        /** @var BlockFillFailure $failure */
        $failure = $result->failures->items()[0];
        $this->assertSame('page-2', $failure->page_slug);
        $this->assertStringContainsString('silently absent', $failure->reason);
        $this->assertStringContainsString('job never wrote', $failure->reason);

        // Faithful-rebuild: every expected slug accounted for exactly
        // once. No stubs, no doubles.
        $accountedSlugs = array_merge(
            array_map(static fn (FilledPage $p): string => $p->page_slug, $result->pages->items()),
            array_map(static fn (BlockFillFailure $f): string => $f->page_slug, $result->failures->items()),
        );
        sort($accountedSlugs);
        $this->assertSame(['page-1', 'page-2', 'page-3'], $accountedSlugs);
    }

    #[Test]
    public function chaos_worker_timeout_65s_sleep_vs_60s_kill_surfaces_as_silently_absent(): void
    {
        // Chaos scenario: same shape as SIGKILL — worker timeout at the
        // WORKER level SIGKILLs the process (the job's own $timeout is
        // irrelevant; the worker --timeout wins). Under the pre-fix
        // horizon config (timeout: 60), a Sonnet call that takes 65s
        // would be killed before it completes — no FilledPage, no
        // BlockFillFailure written.
        //
        // Config fix: horizon.php supervisor-block-fill.timeout = 600.
        // Sonnet calls have 600s headroom, matching the job's own
        // $timeout. This test proves that even IF the timeout DID fire
        // (misconfiguration, resource exhaustion), reconcile catches
        // the loss visibly.
        $store = $this->primeConversation('conv-timeout');

        // page-1 completes just in time; page-2 timed out (no write);
        // page-3 completes.
        $store->putFilledPage('conv-timeout', $this->stubFilledPage('page-1'));
        $store->putFilledPage('conv-timeout', $this->stubFilledPage('page-3'));

        $result = $this->makeBlockFill()->reconcile('conv-timeout');

        $this->assertSame(BlockFillStatus::Partial, $result->status);
        $this->assertSame(2, $result->pages->count());
        $this->assertSame(1, $result->failures->count());
        $this->assertSame('page-2', $result->failures->items()[0]->page_slug);
        $this->assertStringContainsString('silently absent', $result->failures->items()[0]->reason);
    }

    #[Test]
    public function chaos_callback_never_fires_sweeper_picks_up_orphan_reconciles(): void
    {
        // Chaos scenario: every job completes and writes its result to
        // the store. But the batch's finally() callback (which dispatches
        // ReconcileBlockFillJob) fails to fire — the callback JOB itself
        // OOMs, Redis blips at the wrong moment, or the callback payload
        // gets evicted. The reconcile-state exists but the
        // reconciled-result doesn't. Downstream stages see nothing.
        //
        // The scheduled sweeper (engine:reconcile-stuck-conversions,
        // 1-min cadence) is the ONLY recovery. It scans for
        // reconcile-states without a matching reconciled-result and
        // re-invokes reconcile.
        $store = $this->primeConversation('conv-callback-lost');

        // All 3 workers succeeded — full result set is in the per-page
        // namespace.
        $store->putFilledPage('conv-callback-lost', $this->stubFilledPage('page-1'));
        $store->putFilledPage('conv-callback-lost', $this->stubFilledPage('page-2'));
        $store->putFilledPage('conv-callback-lost', $this->stubFilledPage('page-3'));

        // But ReconcileBlockFillJob never ran (callback was lost).
        // Reconciled-result is empty.
        $this->assertNull(
            $store->getReconciledResult('conv-callback-lost'),
            'precondition: callback did not fire, so reconciled-result is not present'
        );

        // Sweeper picks up the orphan on its next tick — it calls
        // reconcile() directly (idempotent, safe).
        $result = $this->makeBlockFill()->reconcile('conv-callback-lost');

        $this->assertSame(BlockFillStatus::Complete, $result->status, 'sweeper reconciles orphan to Complete');
        $this->assertSame(3, $result->pages->count(), 'all 3 FilledPages recovered from the per-page store');
        $this->assertSame(0, $result->failures->count());

        // Idempotency: subsequent sweeper tick (still every minute)
        // finds the reconciled-result and no-ops.
        $second = $this->makeBlockFill()->reconcile('conv-callback-lost');
        $this->assertSame($result->pages->count(), $second->pages->count());
        $this->assertSame($result->failures->count(), $second->failures->count());
    }

    #[Test]
    public function chaos_redis_eviction_of_queued_job_sweeper_is_only_recovery(): void
    {
        // Chaos scenario: Redis is misconfigured with maxmemory-policy=
        // allkeys-lru (a common managed-Redis default). Under memory
        // pressure, Redis evicts queued job payloads. A job vanishes
        // without executing — its slug never appears in the store, its
        // pending_jobs counter never decrements, the batch never
        // completes, the callback never fires.
        //
        // From the reconciliation's point of view, this is the SAME
        // failure mode as (3): reconcile-state exists but
        // reconciled-result doesn't, plus the missing job's slug is
        // never in the per-page store. Sweeper picks it up, surfaces
        // "silently absent" for the evicted slug.
        //
        // docker-compose.yml pins maxmemory-policy=noeviction. This
        // test documents the failure mode + proves the sweeper is the
        // one recovery path when Redis is misconfigured.
        $store = $this->primeConversation('conv-redis-evict');

        // Two workers succeeded; page-2's queued job was evicted from
        // Redis before a worker could pick it up. No FilledPage, no
        // BlockFillFailure — same shape as SIGKILL, different upstream
        // cause.
        $store->putFilledPage('conv-redis-evict', $this->stubFilledPage('page-1'));
        $store->putFilledPage('conv-redis-evict', $this->stubFilledPage('page-3'));

        // The batch never completed (pending_jobs never hit zero for
        // the evicted job), so the callback never fired.
        $this->assertNull(
            $store->getReconciledResult('conv-redis-evict'),
            'precondition: evicted job leaves batch incomplete, callback never fires'
        );

        // Sweeper's tick picks it up.
        $result = $this->makeBlockFill()->reconcile('conv-redis-evict');

        $this->assertSame(BlockFillStatus::Partial, $result->status);
        $this->assertSame(2, $result->pages->count());
        $this->assertSame(1, $result->failures->count());
        $this->assertSame('page-2', $result->failures->items()[0]->page_slug);
        $this->assertStringContainsString('silently absent', $result->failures->items()[0]->reason);
    }

    #[Test]
    public function chaos_full_conversion_recovery_two_workers_died_sweeper_produces_correct_partial(): void
    {
        // Cross-cutting chaos: multiple failures across the batch.
        // 5-page conversion, page-1 succeeds, page-2 SIGKILL, page-3
        // succeeds, page-4 timeout, page-5 succeeds. Callback then fails.
        // Sweeper picks it up and reconciles to Partial with 3 successes,
        // 2 silently-absent failures.
        Bus::fake();
        $this->app->instance(BlockFillAgent::class, new FakeBlockFillAgent);
        [$irPass, $plan, $manifest] = $this->stubIr(5);

        $blockFill = $this->makeBlockFill();
        $blockFill->dispatch($irPass, $plan, $manifest, 'conv-multi');

        $store = $this->makeStore();
        $store->putFilledPage('conv-multi', $this->stubFilledPage('page-1'));
        // page-2 killed
        $store->putFilledPage('conv-multi', $this->stubFilledPage('page-3'));
        // page-4 timed out
        $store->putFilledPage('conv-multi', $this->stubFilledPage('page-5'));

        $result = $blockFill->reconcile('conv-multi');

        $this->assertSame(BlockFillStatus::Partial, $result->status);
        $this->assertSame(3, $result->pages->count());
        $this->assertSame(2, $result->failures->count());

        $failedSlugs = array_map(
            static fn (BlockFillFailure $f): string => $f->page_slug,
            $result->failures->items(),
        );
        sort($failedSlugs);
        $this->assertSame(['page-2', 'page-4'], $failedSlugs);
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
                    props: ['body' => "stub body for {$slug}"],
                    source_brief: 'stub',
                    source_quote: 'stub',
                ),
            ]),
            self_assessment: 'chaos-test stub',
            confidence: 1.0,
        );
    }

    /**
     * @return array{0: IrPassResult, 1: SitePlan, 2: Manifest}
     */
    private function stubIr(int $count): array
    {
        $pages = [];
        $refs = [];
        $inventoryPages = [];
        $sourceUrl = 'https://example.test';

        for ($i = 1; $i <= $count; $i++) {
            $slug = "page-{$i}";
            $url = "/pages/{$i}";
            $absoluteUrl = $sourceUrl.$url;

            $pages[] = new Ir(
                page_slug: $slug,
                page_title: "Page {$i}",
                nav_order: $i - 1,
                blocks: new DataCollection(IrBlock::class, [
                    new IrBlock(component_type: 'heading', content_brief: 'headline'),
                ]),
            );

            $refs[] = new ContentRef(
                url: $absoluteUrl,
                scrape_ref: "scrapes/{$slug}.json",
                title: "Page {$i}",
            );

            $inventoryPages[] = new InventoryPage(
                label: "Page {$i}",
                url: $url,
                kind: 'page',
                node_type: 'Page',
                page_node_id: $i,
                external_subtype: null,
                depth: 0,
                nav_path: [],
                has_children: false,
            );

            Storage::disk(self::DISK)->put("scrapes/{$slug}.json", json_encode([
                'markdown' => "# Page {$i}",
                'image_urls' => [],
            ]));
        }

        $irPass = new IrPassResult(
            style_brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: [],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
            pages: new DataCollection(Ir::class, $pages),
            failures: new DataCollection(IrPassFailure::class, []),
            status: IrPassStatus::Complete,
        );

        $plan = new SitePlan(
            nav: new DataCollection(NavItem::class, []),
            kept_pages: new DataCollection(InventoryPage::class, $inventoryPages),
            ledger: new DecisionLedger(
                entries: new DataCollection(DecisionEntry::class, []),
            ),
        );

        $manifest = new Manifest(
            source_url: $sourceUrl,
            org_id: 'ngin-test',
            structure: new SiteStructure(
                nav: new DataCollection(NavNode::class, []),
                pages_total: 0,
            ),
            provisioning: null,
            brand: new Brand(logo_source: 'flag'),
            content_refs: new DataCollection(ContentRef::class, $refs),
            asset_refs: new DataCollection(AssetRef::class, []),
            confidence: 1.0,
        );

        return [$irPass, $plan, $manifest];
    }
}
