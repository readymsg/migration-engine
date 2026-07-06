<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\AssetRef;
use App\Data\BlockFillResult;
use App\Data\Brand;
use App\Data\ContentRef;
use App\Data\DecisionEntry;
use App\Data\DecisionLedger;
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
use App\Services\Generate\BlockFillResultStore;
use App\Services\Generate\FixtureReplayingBlockFillAgent;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Spatie\LaravelData\DataCollection;

// Tier-4 async validation: replay a captured BlockFillResult fixture
// through the FULL async path (Bus::batch on Redis + real Horizon
// worker + finally callback + cross-process reconcile). Zero LLM
// cost — the fake agent returns the fixture's FilledPage for each slug.
//
// The point isn't testing the agent — it's testing the async
// orchestration at REAL SCALE (31 pages for cjfl) with REAL DATA
// payloads (production-shaped FilledBlocks with real props). Tier-1's
// 3 synthetic pages proved the mechanism; Tier-4 confirms nothing in
// the larger payload / higher slug count / larger cache reads breaks
// cross-process.
//
// Prerequisites:
//   1. Redis running (docker-compose OR brew): redis-cli ping → PONG
//   2. QUEUE_CONNECTION=redis CACHE_STORE=redis env vars
//   3. Horizon running: php artisan horizon
//   4. Then: php artisan engine:async-fixture-replay --fixture=cjfl
//
// Assertion (built into this command, not just diagnostic):
//   The reconciled BlockFillResult MUST match the fixture slug-for-slug:
//   same page count, same failure count, same status, same slug set.
//   Any drift indicates a cross-process bug the small Tier-1 payload
//   didn't surface.
final class AsyncFixtureReplay extends Command
{
    protected $signature = 'engine:async-fixture-replay {--fixture=cjfl : Fixture stem in tests/Fixtures/blockfill/} {--poll-timeout=60 : Seconds to wait for reconcile} {--poll-interval=2}';

    protected $description = 'Replay a captured BlockFillResult fixture through the async path and diff the reconciled result. Tier-4 validation.';

    private const DISK = 'local'; // Persistent disk both dispatcher + worker can read.

    public function handle(BlockFill $blockFill, BlockFillResultStore $store, CacheRepository $cache): int
    {
        // Hard fail if the env var isn't set — otherwise the worker
        // would fall back to real Sonnet and burn real money on stub
        // bodies, exactly the mistake this class exists to prevent.
        if (config('services.blockfill.fixture_replay') !== true) {
            $this->error('BLOCKFILL_FIXTURE_REPLAY=1 must be set in this process AND in the Horizon worker process.');
            $this->line('Restart Horizon with: BLOCKFILL_FIXTURE_REPLAY=1 php artisan horizon');
            $this->line('Then re-run: BLOCKFILL_FIXTURE_REPLAY=1 REDIS_CLIENT=predis QUEUE_CONNECTION=redis CACHE_STORE=redis php artisan engine:async-fixture-replay ...');

            return self::FAILURE;
        }

        $fixture = (string) $this->option('fixture');
        $pollTimeout = (int) $this->option('poll-timeout');
        $pollInterval = (int) $this->option('poll-interval');
        $conversionId = 'tier4-'.$fixture.'-'.time();

        $fixturePath = base_path("tests/Fixtures/blockfill/{$fixture}.json");
        if (! is_file($fixturePath)) {
            $this->error("fixture not found: {$fixturePath}");

            return self::FAILURE;
        }

        try {
            $fixtureResult = $this->loadFixture($fixturePath);
        } catch (RuntimeException $e) {
            $this->error("could not load fixture: {$e->getMessage()}");

            return self::FAILURE;
        }

        $expectedSlugs = array_map(
            static fn (FilledPage $p): string => $p->page_slug,
            $fixtureResult->pages->items(),
        );
        sort($expectedSlugs);

        $this->info('=== Tier-4 async fixture replay ===');
        $this->line("fixture         : {$fixturePath}");
        $this->line("conversion_id   : {$conversionId}");
        $this->line("fixture status  : {$fixtureResult->status->value}");
        $this->line("fixture pages   : {$fixtureResult->pages->count()}");
        $this->line("fixture failures: {$fixtureResult->failures->count()}");
        $this->line('queue           : '.config('queue.default'));
        $this->line('cache           : '.config('cache.default'));
        $this->newLine();

        // Seed the FixtureReplayingBlockFillAgent's per-conversion cache
        // BEFORE dispatch. Every worker's FixtureReplayingBlockFillAgent
        // reads from THIS cache — cross-process shared via Redis in prod,
        // via whatever CACHE_STORE the caller set. No in-memory container
        // binding to worry about (that was the bug in the first attempt
        // — process-local binding didn't cross the queue boundary).
        $seedingAgent = new FixtureReplayingBlockFillAgent($cache);
        foreach ($fixtureResult->pages->items() as $page) {
            /** @var FilledPage $page */
            $seedingAgent->seed($conversionId, $page);
        }
        $this->line('       seeded '.$fixtureResult->pages->count().' FilledPages into fixture-replay cache');

        // Build the synthetic IR/manifest/plan whose slugs match the
        // fixture. Each ContentRef points at a body file written to the
        // real local disk under `tier4-<conversion_id>/` — accessible
        // cross-process (dispatcher + worker share `storage/app`).
        [$irPass, $plan, $manifest] = $this->buildInput($fixtureResult, $conversionId);

        $this->info('[1/3] dispatch');
        $startedAt = microtime(true);
        $blockFill->dispatch($irPass, $plan, $manifest, $conversionId);
        $dispatchElapsed = number_format((microtime(true) - $startedAt) * 1000, 1);
        $this->line("       dispatch elapsed: {$dispatchElapsed}ms");
        $this->line('       reconcile-state persisted: '.($store->getReconcileState($conversionId) !== null ? 'yes' : 'NO — BUG'));

        $this->newLine();
        $this->info("[2/3] poll for reconciled-result (timeout {$pollTimeout}s)");
        $reconciled = null;
        $polled = 0;
        while ($reconciled === null && $polled < $pollTimeout) {
            sleep($pollInterval);
            $polled += $pollInterval;
            $reconciled = $store->getReconciledResult($conversionId);
            $this->line("       t+{$polled}s: reconciled=".($reconciled !== null ? 'YES' : 'no'));
        }

        if ($reconciled === null) {
            $this->error('       ✗ never reconciled — is Horizon running?');

            return self::FAILURE;
        }

        $totalElapsed = number_format(microtime(true) - $startedAt, 1);
        $this->newLine();
        $this->info("[3/3] reconciled after {$totalElapsed}s — diff against fixture");
        $this->line("       status         : {$reconciled->status->value}");
        $this->line("       pages          : {$reconciled->pages->count()}  (fixture: {$fixtureResult->pages->count()})");
        $this->line("       failures       : {$reconciled->failures->count()}  (fixture: {$fixtureResult->failures->count()})");

        $actualSlugs = array_map(
            static fn (FilledPage $p): string => $p->page_slug,
            $reconciled->pages->items(),
        );
        sort($actualSlugs);

        $diffOk = true;

        if ($reconciled->status !== $fixtureResult->status) {
            $this->error("       ✗ status drift: fixture={$fixtureResult->status->value}  reconciled={$reconciled->status->value}");
            $diffOk = false;
        }

        if ($reconciled->pages->count() !== $fixtureResult->pages->count()) {
            $this->error("       ✗ page count drift");
            $diffOk = false;
        }

        if ($reconciled->failures->count() !== $fixtureResult->failures->count()) {
            $this->error("       ✗ failure count drift — showing reconciled failures:");
            foreach ($reconciled->failures->items() as $f) {
                /** @var \App\Data\BlockFillFailure $f */
                $this->line("         [{$f->page_slug}] {$f->reason}");
            }
            $diffOk = false;
        }

        $missing = array_values(array_diff($expectedSlugs, $actualSlugs));
        $extra = array_values(array_diff($actualSlugs, $expectedSlugs));
        if ($missing !== [] || $extra !== []) {
            $this->error("       ✗ slug set drift");
            if ($missing !== []) {
                $this->line('         missing: '.implode(', ', $missing));
            }
            if ($extra !== []) {
                $this->line('         extra  : '.implode(', ', $extra));
            }
            $diffOk = false;
        }

        // Deep diff: assert each reconciled FilledPage matches the
        // fixture's FilledPage byte-for-byte (via toArray comparison).
        // Any subtle serialization drift in the async path would show up
        // here — same slug, different content.
        $reconciledBySlug = [];
        foreach ($reconciled->pages->items() as $p) {
            /** @var FilledPage $p */
            $reconciledBySlug[$p->page_slug] = $p;
        }
        $contentDrifts = 0;
        foreach ($fixtureResult->pages->items() as $fixturePage) {
            /** @var FilledPage $fixturePage */
            $reconciledPage = $reconciledBySlug[$fixturePage->page_slug] ?? null;
            if ($reconciledPage === null) {
                continue;
            }
            if ($reconciledPage->toArray() !== $fixturePage->toArray()) {
                if ($contentDrifts === 0) {
                    $this->error('       ✗ content drift on:');
                }
                $this->line("         {$fixturePage->page_slug}");
                $contentDrifts++;
            }
        }
        if ($contentDrifts > 0) {
            $diffOk = false;
        }

        if ($diffOk) {
            $this->newLine();
            $this->info('✓ Tier-4 clean: reconciled result matches fixture slug-for-slug, content byte-for-byte');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * @throws RuntimeException
     */
    private function loadFixture(string $path): BlockFillResult
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("read failed: {$path}");
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("invalid json: {$e->getMessage()}");
        }

        return BlockFillResult::from($decoded);
    }

    /**
     * @return array{0: IrPassResult, 1: SitePlan, 2: Manifest}
     */
    private function buildInput(BlockFillResult $fixture, string $conversionId): array
    {
        /** @var array<int, Ir> $irPages */
        $irPages = [];
        /** @var array<int, InventoryPage> $inventoryPages */
        $inventoryPages = [];
        /** @var array<int, ContentRef> $refs */
        $refs = [];
        $sourceUrl = 'https://tier4.replay';

        foreach ($fixture->pages->items() as $i => $page) {
            /** @var FilledPage $page */
            $slug = $page->page_slug;
            $nodeId = $this->nodeIdFromSlug($slug) ?? ($i + 1);
            $url = "/replay/{$slug}";
            $absoluteUrl = $sourceUrl.$url;
            $scrapeRef = "tier4-replay/{$conversionId}/{$slug}.json";

            $irPages[] = new Ir(
                page_slug: $slug,
                page_title: $page->page_title,
                nav_order: $page->nav_order,
                blocks: new DataCollection(IrBlock::class, [
                    new IrBlock(component_type: 'heading', content_brief: 'stub for replay — fake agent ignores'),
                ]),
            );

            $inventoryPages[] = new InventoryPage(
                label: $page->page_title,
                url: $url,
                kind: 'page',
                node_type: 'Page',
                page_node_id: $nodeId,
                external_subtype: null,
                depth: 0,
                nav_path: [],
                has_children: false,
            );

            $refs[] = new ContentRef(
                url: $absoluteUrl,
                scrape_ref: $scrapeRef,
                title: $page->page_title,
            );

            // Real cross-process disk write. The fake agent ignores body
            // content — this file exists purely so ContentLoader on the
            // worker doesn't fail before the agent is even called.
            Storage::disk(self::DISK)->put($scrapeRef, json_encode([
                'markdown' => "# {$page->page_title}\n\nstub body for tier-4 replay",
                'image_urls' => [],
            ], JSON_THROW_ON_ERROR));
        }

        return [
            new IrPassResult(
                style_brief: $fixture->style_brief,
                pages: new DataCollection(Ir::class, $irPages),
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
                // Carry conversion_id in org_id — see
                // FixtureReplayingBlockFillAgent::conversionIdFromOrgId().
                // This side-channel avoids changing BlockFillInput's
                // contract for a validation-only concern.
                org_id: 'ngin-tier4-'.$conversionId,
                structure: new SiteStructure(nav: new DataCollection(NavNode::class, []), pages_total: 0),
                provisioning: null,
                brand: new Brand(logo_source: 'flag'),
                content_refs: new DataCollection(ContentRef::class, $refs),
                asset_refs: new DataCollection(AssetRef::class, []),
                confidence: 1.0,
            ),
        ];
    }

    private function nodeIdFromSlug(string $slug): ?int
    {
        if (preg_match('/^page-(\d+)$/', $slug, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
