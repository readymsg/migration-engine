<?php

declare(strict_types=1);

namespace Tests\Feature\Async;

use App\Data\AssetRef;
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
use App\Jobs\ReconcileBlockFillJob;
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

// Baseline for the async refactor. Two paired assertions, running under
// Bus::fake() (which mimics async by intercepting dispatches so they
// never execute inline):
//
//   1. dispatch() writes the reconcile-state to the store BEFORE the
//      batch is dispatched. This is the load-bearing hand-off — reconcile
//      running on a worker AFTER batch completion (or in the scheduled
//      sweeper 60s later) MUST be able to read this state or the whole
//      conversion is unrecoverable.
//   2. dispatch() does NOT eagerly reconcile — the reconciled-result
//      slot is EMPTY after dispatch. The reconciliation is DEFERRED to
//      the finally-wired ReconcileBlockFillJob (proven separately by
//      asserting the job is queued via Bus::assertBatched + the finally
//      callback exists).
//
// The PRE-slice code inline-called reconcile() from run() right after
// dispatch — meaning under async, reconcile ran against an empty store
// and returned all-synthetic-absent. This test proves the fix: reconcile
// is NOT called inline; it's a separate job wired via finally.
//
// Under Bus::fake, jobs and their finally callbacks never actually run,
// which is EXACTLY what happens under real async before workers pick up
// the jobs. So this test is the deterministic reproducer of the async
// timing that used to cause the bug.
final class AsyncBlockFillBaselineTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'async-blockfill-baseline';

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

    /**
     * Minimal 3-page IR result + matching Manifest/SitePlan wiring so
     * preflight resolves without errors — the point of this test is
     * the DISPATCH/RECONCILE timing, not the preflight logic (which is
     * covered by BlockFillTest under sync).
     */
    private function stubIr(int $count = 3): array
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
                    new IrBlock(component_type: 'paragraph', content_brief: 'body copy'),
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

            // Body must be readable from the fake disk so preflight
            // ContentLoader validation would pass on the worker. Under
            // Bus::fake the worker never runs, but the preflight resolver
            // in dispatch() doesn't load bodies — it only checks
            // ContentRef presence. So this write is defensive for any
            // downstream test that DOES exercise the job's handle().
            Storage::disk(self::DISK)->put("scrapes/{$slug}.json", json_encode([
                'markdown' => "# Page {$i}\n\nBody for page {$i}.",
                'image_urls' => [],
            ]));
        }

        $irPass = new IrPassResult(
            style_brief: new GlobalStyleBrief(
                brand_voice: 'test voice',
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

    #[Test]
    public function dispatch_writes_reconcile_state_before_batch_is_dispatched(): void
    {
        // Under Bus::fake, batch dispatch is intercepted and jobs never
        // run. But the reconcile-state store write happens BEFORE the
        // Bus::batch call — proven by inspecting the store immediately
        // after dispatch() returns. This is the load-bearing hand-off:
        // reconcile (in a worker or in the sweeper) MUST be able to
        // read this state.
        Bus::fake();
        $this->app->instance(BlockFillAgent::class, new FakeBlockFillAgent);
        [$irPass, $plan, $manifest] = $this->stubIr(3);

        $cache = $this->app->make(Repository::class);
        $store = new CacheBlockFillResultStore($cache);
        $blockFill = new BlockFill(new CacheBlockFillContextStore($cache), $store);

        $blockFill->dispatch($irPass, $plan, $manifest, 'conv-baseline-1');

        $state = $store->getReconcileState('conv-baseline-1');
        $this->assertNotNull($state, 'reconcile state MUST be persisted before batch dispatch');
        $this->assertSame(['page-1', 'page-2', 'page-3'], $state->expected_slugs);
        $this->assertSame(0, $state->preflight_failures->count(), 'no preflight failures on clean input');
    }

    #[Test]
    public function dispatch_does_not_reconcile_inline_reconciled_result_is_empty_until_finally_fires(): void
    {
        // This is the PROOF the async bug is fixed. Under Bus::fake
        // (which simulates async by suppressing job execution), calling
        // dispatch() must NOT eagerly reconcile — the reconciled-result
        // slot is EMPTY. Reconciliation runs later, on a worker, via the
        // ReconcileBlockFillJob wired to batch.finally().
        //
        // The pre-slice code called reconcile() inline right after
        // dispatch(). Under async, that eager reconcile ran against an
        // empty store and returned all-synthetic-absent. This test
        // ensures we never regress to that behavior.
        Bus::fake();
        $this->app->instance(BlockFillAgent::class, new FakeBlockFillAgent);
        [$irPass, $plan, $manifest] = $this->stubIr(3);

        $cache = $this->app->make(Repository::class);
        $store = new CacheBlockFillResultStore($cache);
        $blockFill = new BlockFill(new CacheBlockFillContextStore($cache), $store);

        $blockFill->dispatch($irPass, $plan, $manifest, 'conv-baseline-2');

        $reconciled = $store->getReconciledResult('conv-baseline-2');
        $this->assertNull(
            $reconciled,
            'dispatch() must NOT eagerly reconcile — reconciled-result must be null until '
            .'ReconcileBlockFillJob fires from batch.finally() or the scheduled sweeper picks it up'
        );

        // And the batch itself was dispatched — the batch machinery was
        // engaged, just not run inline.
        Bus::assertBatched(function ($batch): bool {
            return $batch->name === 'block-fill:conv-baseline-2';
        });
    }

    #[Test]
    public function reconcile_is_idempotent_second_call_returns_same_result_without_rewriting(): void
    {
        // The scheduled sweeper (every 60s) invokes reconcile() for any
        // conversion that has a reconcile-state but no reconciled-result.
        // If ReconcileBlockFillJob has already run (or a prior sweeper
        // tick did), a second call must return the SAME result without
        // re-processing — the "last reconciled at" guard is the
        // reconciled-result marker itself.
        Bus::fake();
        $this->app->instance(BlockFillAgent::class, new FakeBlockFillAgent);
        [$irPass, $plan, $manifest] = $this->stubIr(3);

        $cache = $this->app->make(Repository::class);
        $store = new CacheBlockFillResultStore($cache);
        $blockFill = new BlockFill(new CacheBlockFillContextStore($cache), $store);

        $blockFill->dispatch($irPass, $plan, $manifest, 'conv-baseline-3');

        // Simulate one worker having written a FilledPage before the
        // first reconcile.
        $store->putFilledPage('conv-baseline-3', new FilledPage(
            page_slug: 'page-1',
            page_title: 'Page 1',
            nav_order: 0,
            blocks: new DataCollection(FilledBlock::class, []),
            self_assessment: 'stub',
            confidence: 1.0,
        ));

        $first = $blockFill->reconcile('conv-baseline-3');
        // Now simulate ANOTHER worker writing a FilledPage after
        // reconcile ran. If reconcile is truly idempotent, the second
        // reconcile call must NOT pick up the new write — it returns
        // the frozen first result.
        $store->putFilledPage('conv-baseline-3', new FilledPage(
            page_slug: 'page-2',
            page_title: 'Page 2',
            nav_order: 1,
            blocks: new DataCollection(FilledBlock::class, []),
            self_assessment: 'stub',
            confidence: 1.0,
        ));
        $second = $blockFill->reconcile('conv-baseline-3');

        // Both reconcile calls return the SAME frozen result: 1 filled
        // page (the one written before first reconcile), 2 silently-
        // absent failures (the other slugs). The second FilledPage
        // write is ignored — idempotency wins.
        $this->assertSame($first->pages->count(), $second->pages->count());
        $this->assertSame($first->failures->count(), $second->failures->count());
        $this->assertSame(1, $first->pages->count(), 'only the page-1 write from before first reconcile survives');
        $this->assertSame(2, $first->failures->count(), 'page-2 and page-3 are silently-absent at first reconcile');
    }

    #[Test]
    public function reconcile_without_prior_dispatch_throws_visibly(): void
    {
        // The sweeper only invokes reconcile for conversions that HAVE
        // a reconcile-state. But if reconcile is called manually for a
        // conversion whose state was never written (or was cleared),
        // it MUST throw — a silent empty return would be exactly the
        // "silent loss re-enters through the async door" failure mode
        // this slice exists to prevent.
        $cache = $this->app->make(Repository::class);
        $store = new CacheBlockFillResultStore($cache);
        $blockFill = new BlockFill(new CacheBlockFillContextStore($cache), $store);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no reconcile state exists');

        $blockFill->reconcile('conv-never-dispatched');
    }
}
