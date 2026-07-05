<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\AssetRef;
use App\Data\Brand;
use App\Data\ContentRef;
use App\Data\DecisionEntry;
use App\Data\DecisionLedger;
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
use App\Services\Generate\BlockFillResultStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Generate\FakeBlockFillAgent;

// Companion to the AsyncBlockFillBaselineTest + AsyncBlockFillChaosTest
// PHPUnit suites. Those tests prove the LOGIC (reconcile handles the
// silent-loss doors correctly) under sync + Bus::fake — this artisan
// command exercises the same code path against REAL Redis + REAL
// Horizon workers, so the machinery (worker startup, batch dispatch,
// finally callback firing, cross-process cache reads) is validated
// too.
//
// Prerequisites:
//   1. docker compose up -d  (starts Redis with noeviction policy)
//   2. QUEUE_CONNECTION=redis CACHE_STORE=redis in .env (or per-run)
//   3. php artisan horizon (in another terminal — supervisor picks up
//      the block-fill queue)
//   4. php artisan engine:async-smoke-test [--pages=N]
//
// Expected outcome under real async: dispatch() returns immediately,
// getReconciledResult() returns null (batch is running), poll until
// reconciled-result appears (should be seconds — fake agent is fast).
// The reconciled result matches sync behavior slug-for-slug.
//
// If dispatch is against sync queue (QUEUE_CONNECTION not set to redis),
// the whole thing runs inline in this process and getReconciledResult
// is populated immediately — proves the sync fallback works too.
final class AsyncSmokeTest extends Command
{
    protected $signature = 'engine:async-smoke-test {--pages=3} {--poll-timeout=30} {--poll-interval=1}';

    protected $description = 'Dispatch a fake-agent block-fill batch and poll for reconciliation. Proves async correctness against real Redis+Horizon.';

    private const DISK = 'async-smoke';

    public function handle(BlockFill $blockFill, BlockFillResultStore $store): int
    {
        $pages = (int) $this->option('pages');
        $pollTimeout = (int) $this->option('poll-timeout');
        $pollInterval = (int) $this->option('poll-interval');
        $conversionId = 'smoke-'.Str::random(8);

        // Rebind ContentLoader against a local disk we can seed. The
        // fake disk isn't shared with any live storage.
        Storage::fake(self::DISK);
        $this->getLaravel()->instance(
            \App\Services\Generate\ContentLoader::class,
            new \App\Services\Generate\ContentLoader(disk: self::DISK),
        );
        $this->getLaravel()->instance(BlockFillAgent::class, new FakeBlockFillAgent);

        $this->info('=== Async smoke test ===');
        $this->line("conversion_id : {$conversionId}");
        $this->line("pages         : {$pages}");
        $this->line('queue         : '.config('queue.default'));
        $this->line('cache         : '.config('cache.default'));
        $this->newLine();

        [$irPass, $plan, $manifest] = $this->buildStub($pages, $conversionId);

        $this->info('[1/3] dispatch (returns immediately under async, blocks under sync)');
        $startedAt = microtime(true);
        $blockFill->dispatch($irPass, $plan, $manifest, $conversionId);
        $dispatchElapsed = number_format((microtime(true) - $startedAt) * 1000, 1);
        $this->line("       dispatch elapsed: {$dispatchElapsed}ms");

        // Immediately after dispatch: reconcile-state must exist (load-
        // bearing hand-off), reconciled-result may or may not exist
        // depending on sync vs async.
        $state = $store->getReconcileState($conversionId);
        $this->line('       reconcile-state present: '.($state !== null ? 'yes' : 'NO — BUG'));
        if ($state === null) {
            $this->error('       ✗ reconcile-state missing after dispatch — dispatch() did not persist state');

            return self::FAILURE;
        }

        $earlyReconciled = $store->getReconciledResult($conversionId);
        $this->line('       reconciled-result present immediately: '.($earlyReconciled !== null ? 'yes (sync queue)' : 'no (async — will poll)'));

        $this->newLine();
        $this->info('[2/3] poll for reconciled-result (timeout '.$pollTimeout.'s)');
        $polled = 0;
        $reconciled = $earlyReconciled;
        while ($reconciled === null && $polled < $pollTimeout) {
            sleep($pollInterval);
            $polled += $pollInterval;
            $reconciled = $store->getReconciledResult($conversionId);
            $this->line("       t+{$polled}s: reconciled=".($reconciled !== null ? 'YES' : 'no'));
        }

        if ($reconciled === null) {
            $this->error('       ✗ never reconciled — is Horizon running? Check queue:work / horizon status.');
            $this->line('       Diagnostic: batch was dispatched to queue: block-fill');
            $this->line('       Sweeper (1-min) would eventually reconcile this as stuck — try `php artisan engine:reconcile-stuck-conversions` or wait 15 min.');

            return self::FAILURE;
        }

        $totalElapsed = number_format(microtime(true) - $startedAt, 1);
        $this->newLine();
        $this->info("[3/3] reconciled after {$totalElapsed}s");
        $this->line("       status         : {$reconciled->status->value}");
        $this->line("       pages          : {$reconciled->pages->count()} (expected {$pages})");
        $this->line("       failures       : {$reconciled->failures->count()}");

        $expectedSlugs = [];
        for ($i = 1; $i <= $pages; $i++) {
            $expectedSlugs[] = "page-{$i}";
        }
        sort($expectedSlugs);

        $accountedSlugs = array_merge(
            array_map(static fn (\App\Data\FilledPage $p): string => $p->page_slug, $reconciled->pages->items()),
            array_map(static fn (\App\Data\BlockFillFailure $f): string => $f->page_slug, $reconciled->failures->items()),
        );
        sort($accountedSlugs);
        $accountedSlugs = array_values(array_unique($accountedSlugs));

        if ($accountedSlugs === $expectedSlugs) {
            $this->info('       ✓ reconciliation clean — every slug accounted for exactly once');
        } else {
            $this->error('       ✗ reconciliation drift:');
            $this->line('         expected : '.implode(', ', $expectedSlugs));
            $this->line('         accounted: '.implode(', ', $accountedSlugs));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: IrPassResult, 1: SitePlan, 2: Manifest}
     */
    private function buildStub(int $count, string $conversionId): array
    {
        /** @var array<int, Ir> $pages */
        $pages = [];
        /** @var array<int, ContentRef> $refs */
        $refs = [];
        /** @var array<int, InventoryPage> $inventoryPages */
        $inventoryPages = [];
        $sourceUrl = 'https://smoke.test';

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
                    new IrBlock(component_type: 'paragraph', content_brief: 'body'),
                ]),
            );

            $refs[] = new ContentRef(
                url: $absoluteUrl,
                scrape_ref: "async-smoke/{$conversionId}/{$slug}.json",
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

            $payload = json_encode([
                'markdown' => "# Page {$i}\n\nSmoke test body.",
                'image_urls' => [],
            ], JSON_THROW_ON_ERROR);
            Storage::disk(self::DISK)->put("async-smoke/{$conversionId}/{$slug}.json", $payload);
        }

        return [
            new IrPassResult(
                style_brief: new GlobalStyleBrief(
                    brand_voice: 'smoke test',
                    palette: [],
                    layout_conventions: [],
                    nav: new DataCollection(NavItem::class, []),
                ),
                pages: new DataCollection(Ir::class, $pages),
                failures: new DataCollection(IrPassFailure::class, []),
                status: IrPassStatus::Complete,
            ),
            new SitePlan(
                nav: new DataCollection(NavItem::class, []),
                kept_pages: new DataCollection(InventoryPage::class, $inventoryPages),
                ledger: new DecisionLedger(entries: new DataCollection(DecisionEntry::class, [])),
            ),
            new Manifest(
                source_url: $sourceUrl,
                org_id: 'ngin-smoke',
                structure: new SiteStructure(nav: new DataCollection(NavNode::class, []), pages_total: 0),
                provisioning: null,
                brand: new Brand(logo_source: 'flag'),
                content_refs: new DataCollection(ContentRef::class, $refs),
                asset_refs: new DataCollection(AssetRef::class, []),
                confidence: 1.0,
            ),
        ];
    }
}
