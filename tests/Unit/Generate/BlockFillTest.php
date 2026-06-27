<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\AssetRef;
use App\Data\BlockFillFailure;
use App\Data\BlockFillStatus;
use App\Data\Brand;
use App\Data\ContentExtractionFailure;
use App\Data\ContentRef;
use App\Data\DecisionAction;
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
use App\Services\Generate\IrPass;
use App\Services\Generate\PageSlug;
use App\Services\Plan\RootNavPlanner;
use App\Services\Plan\SePlatformContentDetector;
use App\Services\Schema\ComponentSchema;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Generate\FakeBlockFillAgent;
use Tests\Support\Generate\FakeIrPassAgent;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;
use Tests\TestCase;

// Block-fill (GENERATE stage 3 slice 2c) — orchestration tests against the
// FakeBlockFillAgent + a real on-disk corpus where useful. Asserts the
// faithful-rebuild guarantee:
//
//   1. Reconciliation is the authority — every IrPassResult.pages slug
//      ends up in BlockFillResult.pages OR BlockFillResult.failures,
//      exactly once. Never a stub, never a silent absence.
//   2. Body unreadable / no ContentRef → BlockFillFailure WITHOUT a
//      Sonnet call burned.
//   3. Per-page agent failure → BlockFillFailure for that slug only;
//      other pages still succeed; status flips Partial.
//   4. Agent receives full body markdown verbatim + the GlobalStyleBrief
//      (fabrication-relevant wiring).
//   5. Schema-shaped props match ComponentSchema for known component_types.
//
// RefreshDatabase: Bus::batch uses DatabaseBatchRepository which inserts
// into job_batches even in sync mode. Migrations run against the in-
// memory SQLite from phpunit.xml.
final class BlockFillTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'scrapes-blockfill-test';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(self::DISK);

        // ContentLoader is resolved inside GeneratePageJob via DI. The
        // production binding uses the configured services.scrapes.disk
        // (defaults to 's3'); for tests we need it to read from our
        // fake disk. Bind a test-specific instance.
        $this->app->instance(ContentLoader::class, new ContentLoader(disk: self::DISK));
    }

    private function makeIrPass(FakeIrPassAgent $agent): IrPass
    {
        return new IrPass($agent, new ContentLoader(disk: self::DISK));
    }

    private function makeBlockFill(): BlockFill
    {
        // Cache-backed stores in tests use the array driver (phpunit.xml
        // CACHE_STORE=array) — no migrations needed for the cache.
        $cache = $this->app->make(Repository::class);

        return new BlockFill(
            new CacheBlockFillContextStore($cache),
            new CacheBlockFillResultStore($cache),
        );
    }

    #[Test]
    public function clean_run_produces_one_filled_page_per_ir_page_status_complete(): void
    {
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $irPass = $this->makeIrPass(new FakeIrPassAgent)->run($plan, $manifest);
        $this->assertSame(IrPassStatus::Complete, $irPass->status);
        $this->assertGreaterThan(0, $irPass->pages->count());

        $agent = new FakeBlockFillAgent;
        $this->app->instance(BlockFillAgent::class, $agent);

        $result = $this->makeBlockFill()->run($irPass, $plan, $manifest, 'conv-clean');

        $this->assertSame(BlockFillStatus::Complete, $result->status);
        $this->assertSame($irPass->pages->count(), $result->pages->count());
        $this->assertCount(0, $result->failures);
        $this->assertSame($agent->calls, $irPass->pages->count(), 'one agent call per IR page');

        // Reconciliation tie-out: every IR slug accounted for, exactly once.
        $expectedSlugs = array_map(
            static fn (Ir $ir): string => $ir->page_slug,
            $irPass->pages->items(),
        );
        sort($expectedSlugs);
        $actualSlugs = array_map(
            static fn (FilledPage $p): string => $p->page_slug,
            $result->pages->items(),
        );
        sort($actualSlugs);
        $this->assertSame($expectedSlugs, $actualSlugs);
    }

    #[Test]
    public function agent_receives_full_body_markdown_and_style_brief_verbatim(): void
    {
        // The reason this slice exists: real copy is written FROM the
        // page body. If the body doesn't reach the agent verbatim, the
        // fabrication guard breaks down before the prompt even gets a
        // chance to enforce it.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);
        $irPass = $this->makeIrPass(new FakeIrPassAgent)->run($plan, $manifest);

        $agent = new FakeBlockFillAgent;
        $this->app->instance(BlockFillAgent::class, $agent);

        $this->makeBlockFill()->run($irPass, $plan, $manifest, 'conv-bodies');

        foreach ($agent->allSeen as $input) {
            $this->assertNotEmpty(
                $input->body_markdown,
                "body_markdown for '{$input->page_slug}' is empty — fabrication guard would have nothing to anchor on"
            );

            // Same brief reaches every job (coherence lever).
            $this->assertSame(
                $irPass->style_brief->brand_voice,
                $input->style_brief->brand_voice,
            );
            $this->assertSame(
                $irPass->style_brief->palette,
                $input->style_brief->palette,
            );

            // Ir reaches the job verbatim.
            $this->assertSame($input->page_slug, $input->ir->page_slug);
            $this->assertGreaterThan(0, $input->ir->blocks->count());
        }
    }

    #[Test]
    public function per_page_agent_failure_yields_only_that_slug_as_failure_no_stub_others_succeed(): void
    {
        // The exposure this guards against: a single page's exception
        // must NOT (a) cancel the rest of the batch, (b) write a stub
        // FilledPage, or (c) get silently swallowed. The slug must land
        // in failures with a real reason; every other slug succeeds;
        // status flips Partial.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);
        $irPass = $this->makeIrPass(new FakeIrPassAgent)->run($plan, $manifest);

        $allSlugs = array_map(
            static fn (Ir $ir): string => $ir->page_slug,
            $irPass->pages->items(),
        );
        $victim = $allSlugs[0];

        $agent = new FakeBlockFillAgent;
        $agent->throwForSlug($victim, new RuntimeException('mock terminal failure: malformed JSON'));
        $this->app->instance(BlockFillAgent::class, $agent);

        $result = $this->makeBlockFill()->run($irPass, $plan, $manifest, 'conv-partial');

        $this->assertSame(BlockFillStatus::Partial, $result->status);
        $this->assertSame(count($allSlugs) - 1, $result->pages->count());
        $this->assertCount(1, $result->failures);

        $failure = $result->failures[0];
        $this->assertSame($victim, $failure->page_slug);
        $this->assertStringContainsString('mock terminal failure', $failure->reason);

        $survivorSlugs = array_map(
            static fn (FilledPage $p): string => $p->page_slug,
            $result->pages->items(),
        );
        $this->assertNotContains(
            $victim,
            $survivorSlugs,
            'failed page must not appear in pages as a stub'
        );
    }

    #[Test]
    public function missing_content_ref_at_preflight_fails_immediately_no_agent_call(): void
    {
        // Build an IR result whose slugs DON'T resolve to ContentRefs in
        // the Manifest — block-fill must flag every one BEFORE any
        // GeneratePageJob is dispatched, never burning a Sonnet call.
        $manifest = $this->minimalManifestWithoutContentRefs();
        $plan = $this->planWithSyntheticPages($manifest);
        $irPass = $this->syntheticIrPassResult($plan);

        $agent = new FakeBlockFillAgent;
        $this->app->instance(BlockFillAgent::class, $agent);

        $result = $this->makeBlockFill()->run($irPass, $plan, $manifest, 'conv-noref');

        $this->assertSame(0, $agent->calls, 'preflight failure: no Sonnet call burned');
        $this->assertSame(BlockFillStatus::Partial, $result->status);
        $this->assertCount(0, $result->pages);
        $this->assertSame($irPass->pages->count(), $result->failures->count());

        foreach ($result->failures as $failure) {
            /** @var BlockFillFailure $failure */
            $this->assertStringContainsString('content was never captured', $failure->reason);
        }
    }

    #[Test]
    public function ingest_failure_is_surfaced_through_block_fill_failure_reason(): void
    {
        // When the Manifest has an explicit ContentExtractionFailure for
        // a URL, the BlockFillFailure carries the underlying ingest reason
        // so a reviewer can tell "Firecrawl 5xx'd" from "page never
        // queued".
        $manifest = $this->minimalManifestWithIngestFailure();
        $plan = $this->planWithSyntheticPages($manifest);
        $irPass = $this->syntheticIrPassResult($plan);

        $this->app->instance(BlockFillAgent::class, new FakeBlockFillAgent);

        $result = $this->makeBlockFill()->run($irPass, $plan, $manifest, 'conv-ingest-fail');

        $reasons = array_map(
            static fn (BlockFillFailure $f): string => $f->reason,
            $result->failures->items(),
        );
        $withIngestReason = array_filter(
            $reasons,
            static fn (string $r): bool => str_contains($r, 'ingest failure: firecrawl_timeout'),
        );
        $this->assertNotEmpty(
            $withIngestReason,
            'preflight failure must preserve the upstream ContentExtractionFailure.reason'
        );
    }

    #[Test]
    public function every_ir_page_lands_in_pages_or_failures_never_silently_absent(): void
    {
        // Strong tie-out across the slice: enumerate every IrPassResult
        // page slug, confirm we account for ALL of them across
        // BlockFillResult.pages + failures with no gaps and no dups.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);
        $irPass = $this->makeIrPass(new FakeIrPassAgent)->run($plan, $manifest);

        $expected = array_map(
            static fn (Ir $ir): string => $ir->page_slug,
            $irPass->pages->items(),
        );
        sort($expected);

        $agent = new FakeBlockFillAgent;
        // Fail one page so we exercise the both-namespaces path.
        $agent->throwForSlug($expected[1], new RuntimeException('mock fail'));
        $this->app->instance(BlockFillAgent::class, $agent);

        $result = $this->makeBlockFill()->run($irPass, $plan, $manifest, 'conv-tieout');

        $actual = array_merge(
            array_map(
                static fn (FilledPage $p): string => $p->page_slug,
                $result->pages->items(),
            ),
            array_map(
                static fn (BlockFillFailure $f): string => $f->page_slug,
                $result->failures->items(),
            ),
        );
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertSame(
            count($actual),
            count(array_unique($actual)),
            'no slug appears twice (would mean both filled and failed)'
        );
    }

    #[Test]
    public function ir_pass_failed_status_propagates_with_every_page_in_failures(): void
    {
        // When the upstream IrPassResult is itself Failed (e.g. over-
        // capacity), block-fill must NOT dispatch jobs and must surface
        // every upstream failure as a BlockFillFailure with a chained
        // reason so SCORE & LOG sees the page once across stages.
        $upstreamFailures = new DataCollection(IrPassFailure::class, [
            new IrPassFailure(
                page_slug: 'page-1',
                page_title: 'Page 1',
                page_node_id: 1,
                reason: 'single-call IR capacity exceeded',
            ),
            new IrPassFailure(
                page_slug: 'page-2',
                page_title: 'Page 2',
                page_node_id: 2,
                reason: 'single-call IR capacity exceeded',
            ),
        ]);

        $irPass = new IrPassResult(
            style_brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: [],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
            pages: new DataCollection(Ir::class, []),
            failures: $upstreamFailures,
            status: IrPassStatus::Failed,
        );

        $manifest = $this->minimalManifestWithoutContentRefs();
        $plan = $this->planWithSyntheticPages($manifest);

        $agent = new FakeBlockFillAgent;
        $this->app->instance(BlockFillAgent::class, $agent);

        $result = $this->makeBlockFill()->run($irPass, $plan, $manifest, 'conv-ir-failed');

        $this->assertSame(0, $agent->calls);
        $this->assertSame(BlockFillStatus::Failed, $result->status);
        $this->assertCount(0, $result->pages);
        $this->assertCount(2, $result->failures);
        foreach ($result->failures as $failure) {
            /** @var BlockFillFailure $failure */
            $this->assertStringStartsWith('ir-pass-failure:', $failure->reason);
        }
    }

    #[Test]
    public function ir_pass_partial_failures_pass_through_with_chained_reason(): void
    {
        // The conversion log's "every page accounted for" guarantee
        // chains across stages: IR-pass failures (LLM dropped a page,
        // or no captured body) must surface in block-fill output as
        // failures with the upstream reason preserved.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);
        $cleanResult = $this->makeIrPass(new FakeIrPassAgent)->run($plan, $manifest);

        // Synthesise a Partial IrPassResult: same pages, with one extra
        // upstream failure tacked on.
        $partial = new IrPassResult(
            style_brief: $cleanResult->style_brief,
            pages: $cleanResult->pages,
            failures: new DataCollection(IrPassFailure::class, [
                new IrPassFailure(
                    page_slug: 'page-orphan',
                    page_title: 'Orphan',
                    page_node_id: null,
                    reason: 'missing from initial response and from targeted retry',
                ),
            ]),
            status: IrPassStatus::Partial,
        );

        $this->app->instance(BlockFillAgent::class, new FakeBlockFillAgent);

        $result = $this->makeBlockFill()->run($partial, $plan, $manifest, 'conv-partial-upstream');

        $this->assertSame(BlockFillStatus::Partial, $result->status);
        // One synthetic upstream failure surfaces with the chained reason.
        $orphanFailures = array_filter(
            $result->failures->items(),
            static fn (BlockFillFailure $f): bool => $f->page_slug === 'page-orphan',
        );
        $this->assertCount(1, $orphanFailures);
        /** @var BlockFillFailure $orphan */
        $orphan = array_values($orphanFailures)[0];
        $this->assertStringStartsWith('ir-pass-failure:', $orphan->reason);
        $this->assertStringContainsString('targeted retry', $orphan->reason);
    }

    #[Test]
    public function filled_block_props_match_component_schema_field_keys_for_known_types(): void
    {
        // Schema-shaped props: every emitted FilledBlock's `props` keys
        // should be a subset of the ComponentSchema field keys for that
        // component_type. The deterministic assembler validates strictly
        // in a later slice; this test just proves the wiring (agent →
        // FilledBlock → DTO) preserves the schema shape.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);
        $irPass = $this->makeIrPass(new FakeIrPassAgent)->run($plan, $manifest);

        $agent = new FakeBlockFillAgent;
        $this->app->instance(BlockFillAgent::class, $agent);

        $result = $this->makeBlockFill()->run($irPass, $plan, $manifest, 'conv-schema');

        $schema = $this->app->make(ComponentSchema::class);
        foreach ($result->pages as $page) {
            /** @var FilledPage $page */
            foreach ($page->blocks as $block) {
                /** @var FilledBlock $block */
                $def = $schema->get($block->component_type);
                $this->assertNotNull(
                    $def,
                    "FilledBlock component_type '{$block->component_type}' not in ComponentSchema"
                );
                $allowedKeys = array_keys($def->fields);
                foreach (array_keys($block->props) as $key) {
                    $this->assertContains(
                        $key,
                        $allowedKeys,
                        "prop '{$key}' on {$block->component_type} is not a schema field"
                    );
                }
            }
        }
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    private function minimalManifestWithoutContentRefs(): Manifest
    {
        return new Manifest(
            source_url: 'https://example.test/',
            org_id: 'org-test',
            structure: new SiteStructure(
                nav: new DataCollection(NavNode::class, []),
                pages_total: 0,
            ),
            provisioning: null,
            brand: new Brand(logo_source: 'flag'),
            content_refs: new DataCollection(ContentRef::class, []),
            asset_refs: new DataCollection(AssetRef::class, []),
            confidence: 1.0,
        );
    }

    private function minimalManifestWithIngestFailure(): Manifest
    {
        return new Manifest(
            source_url: 'https://example.test/',
            org_id: 'org-test',
            structure: new SiteStructure(
                nav: new DataCollection(NavNode::class, []),
                pages_total: 0,
            ),
            provisioning: null,
            brand: new Brand(logo_source: 'flag'),
            content_refs: new DataCollection(ContentRef::class, []),
            asset_refs: new DataCollection(AssetRef::class, []),
            confidence: 1.0,
            content_failures: new DataCollection(ContentExtractionFailure::class, [
                new ContentExtractionFailure(
                    url: 'https://example.test/page-1',
                    page_title: 'Page 1',
                    page_node_id: 1,
                    reason: 'firecrawl_timeout',
                ),
            ]),
        );
    }

    private function planWithSyntheticPages(Manifest $manifest): SitePlan
    {
        $pages = [];
        $entries = [];
        $nav = [];
        for ($i = 1; $i <= 2; $i++) {
            $page = new InventoryPage(
                label: "Page {$i}",
                url: '/page-'.$i,
                kind: 'page',
                node_type: 'Page',
                page_node_id: $i,
                external_subtype: null,
                depth: 1,
                nav_path: [],
                has_children: false,
            );
            $pages[] = $page;
            $entries[] = new DecisionEntry(
                target: '/page-'.$i,
                action: DecisionAction::Keep,
                reason: 'synthetic',
                confidence: 1.0,
            );
            $nav[] = new NavItem(
                label: $page->label,
                page_slug: PageSlug::of($page),
                order: $i - 1,
            );
        }

        return new SitePlan(
            nav: new DataCollection(NavItem::class, $nav),
            kept_pages: new DataCollection(InventoryPage::class, $pages),
            ledger: new DecisionLedger(
                entries: new DataCollection(DecisionEntry::class, $entries),
            ),
        );
    }

    private function syntheticIrPassResult(SitePlan $plan): IrPassResult
    {
        $pages = [];
        /** @var array<int, InventoryPage> $kept */
        $kept = $plan->kept_pages->items();
        foreach ($kept as $i => $page) {
            $pages[] = new Ir(
                page_slug: PageSlug::of($page),
                page_title: $page->label,
                nav_order: $i,
                blocks: new DataCollection(IrBlock::class, [
                    new IrBlock(component_type: 'hero', content_brief: 'h'),
                ]),
            );
        }

        return new IrPassResult(
            style_brief: new GlobalStyleBrief(
                brand_voice: 'v',
                palette: ['primary' => '#000'],
                layout_conventions: ['c'],
                nav: $plan->nav,
            ),
            pages: new DataCollection(Ir::class, $pages),
            failures: new DataCollection(IrPassFailure::class, []),
            status: IrPassStatus::Complete,
        );
    }
}
