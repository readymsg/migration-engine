<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\DecisionLedger;
use App\Data\GlobalStyleBrief;
use App\Data\InventoryPage;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Data\IrPassAgentResponse;
use App\Data\IrPassFailure;
use App\Data\IrPassInput;
use App\Data\IrPassStatus;
use App\Data\KeepPageContent;
use App\Data\Manifest;
use App\Data\NavItem;
use App\Data\SitePlan;
use App\Services\Extract\BrandExtractor;
use App\Services\Extract\S3AssetUploader;
use App\Services\Extract\ScrapedPage;
use App\Services\Extract\SeCdnRehoster;
use App\Services\Extract\SportNginExtractor;
use App\Services\Generate\ContentLoader;
use App\Services\Generate\IrPass;
use App\Services\Generate\PageSlug;
use App\Services\Plan\RootNavPlanner;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Extract\FakeFirecrawlClient;
use Tests\Support\Extract\FixtureHtmlFetcher;
use Tests\Support\Extract\FixtureRootNavFetcher;
use Tests\Support\Generate\FakeIrPassAgent;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;
use Tests\TestCase;

final class IrPassTest extends TestCase
{
    private const DISK = 'scrapes-irpass-test';

    private const PUCK_PROP_NAMES = [
        'background_image', 'subheading', 'cta_label', 'cta_href', 'columns', 'fields',
    ];

    private const ABSTRACT_VOCAB = [
        'hero', 'heading', 'paragraph', 'image', 'cta', 'columns', 'card', 'list',
        'quote', 'gallery', 'form', 'video', 'team_grid', 'sponsor_strip',
        'social_links', 'contact_card', 'faq_list',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Isolated in-memory test disk for the extractor → ContentLoader
        // round trip. RealManifests::*WithContentCaptured() writes here;
        // ContentLoader reads from here.
        Storage::fake(self::DISK);
    }

    private function makeIrPass(FakeIrPassAgent $agent): IrPass
    {
        return new IrPass($agent, new ContentLoader(disk: self::DISK));
    }

    #[Test]
    public function ir_pass_generates_style_brief_and_ir_for_keep_content_pages_only(): void
    {
        // St. Thomas: 18 nodes; under the default FakeClassifierAgent, 16
        // are kind=page+Keep, 1 is external+Keep (Swag/Spirit Wear), 1 is
        // external+Park (Dibs via SE-platform rule). Only the 16 reach IR.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $agent = new FakeIrPassAgent;
        $result = $this->makeIrPass($agent)->run($plan, $manifest);

        $this->assertSame(IrPassStatus::Complete, $result->status);
        $this->assertCount(0, $result->failures);
        $this->assertSame(1, $agent->calls, 'clean run = one call, no retry');

        $this->assertNotEmpty($result->style_brief->brand_voice);
        $this->assertNotEmpty($result->style_brief->palette);
        $this->assertNotEmpty($result->style_brief->layout_conventions);
        $this->assertSame($plan->nav->count(), $result->style_brief->nav->count());

        $this->assertSame(16, $agent->seen->keep_pages->count());
        $this->assertSame(16, $agent->seen->keep_page_bodies->count(), 'one body per keep page, parallel collections');
        foreach ($agent->seen->keep_pages as $page) {
            /** @var InventoryPage $page */
            $this->assertSame('page', $page->kind);
        }
        $this->assertCount(16, $result->pages);
    }

    #[Test]
    public function agent_input_carries_body_markdown_for_every_keep_page(): void
    {
        // The reason this slice exists: design from REAL bodies, not labels.
        // Assert each keep page that reaches the agent has a corresponding
        // KeepPageContent with a non-empty markdown that's slug-aligned —
        // a missing body would silently degrade IR quality.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $agent = new FakeIrPassAgent;
        $this->makeIrPass($agent)->run($plan, $manifest);

        $this->assertNotNull($agent->seen);

        /** @var array<string, KeepPageContent> $bodyBySlug */
        $bodyBySlug = [];
        /** @var array<int, KeepPageContent> $bodies */
        $bodies = $agent->seen->keep_page_bodies->items();
        foreach ($bodies as $body) {
            $bodyBySlug[$body->page_slug] = $body;
        }

        /** @var array<int, InventoryPage> $pages */
        $pages = $agent->seen->keep_pages->items();
        foreach ($pages as $page) {
            $slug = PageSlug::of($page);
            $this->assertArrayHasKey(
                $slug,
                $bodyBySlug,
                "keep_page '{$slug}' has no matching body — parallel collections out of sync"
            );
            $this->assertNotEmpty(
                $bodyBySlug[$slug]->markdown,
                "body for '{$slug}' is empty — design-from-content would have nothing to work with"
            );
            $this->assertSame($page->label, $bodyBySlug[$slug]->page_title);
        }
    }

    #[Test]
    public function platform_dynamic_subsumed_park_and_external_pages_get_no_ir(): void
    {
        $manifest = RealManifests::tenacityvolleyballWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $agent = new FakeIrPassAgent;
        $this->makeIrPass($agent)->run($plan, $manifest);

        $this->assertNotNull($agent->seen);
        $seenLabels = array_map(
            static fn ($p): string => $p->label,
            $agent->seen->keep_pages->items(),
        );

        $this->assertNotContains('TEAMS', $seenLabels);
        $this->assertNotContains('CALENDAR', $seenLabels);
        $this->assertNotContains('11s & 12s', $seenLabels);
        $this->assertNotContains('13s & 14s', $seenLabels);
        $this->assertNotContains('15s-18s', $seenLabels);

        $this->assertContains('HOME', $seenLabels);
        $this->assertContains('ABOUT US', $seenLabels);
        $this->assertContains('FAQ', $seenLabels);
    }

    #[Test]
    public function ir_blocks_are_schema_agnostic_no_puck_prop_names_in_component_type(): void
    {
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $agent = new FakeIrPassAgent;
        $result = $this->makeIrPass($agent)->run($plan, $manifest);

        foreach ($result->pages as $ir) {
            /** @var Ir $ir */
            foreach ($ir->blocks as $block) {
                /** @var IrBlock $block */
                $this->assertNotContains($block->component_type, self::PUCK_PROP_NAMES);
                $this->assertContains($block->component_type, self::ABSTRACT_VOCAB);
                $this->assertNotEmpty($block->content_brief);
            }
        }

        $json = $result->toJson();
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        foreach ($decoded['pages'] ?? [] as $page) {
            foreach ($page['blocks'] ?? [] as $block) {
                $this->assertSame(
                    ['component_type', 'content_brief', 'asset_refs'],
                    array_keys($block),
                );
            }
        }
    }

    #[Test]
    public function ir_pass_returns_empty_result_when_no_keep_content_pages(): void
    {
        // Synthetic SitePlan with no kept pages — orchestration short-
        // circuits and the agent is never called (no Opus tokens burned).
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $emptyPlan = new SitePlan(
            nav: $plan->nav,
            kept_pages: new DataCollection(InventoryPage::class, []),
            ledger: $plan->ledger,
        );

        $agent = new FakeIrPassAgent;
        $result = $this->makeIrPass($agent)->run($emptyPlan, $manifest);

        $this->assertSame(0, $result->pages->count());
        $this->assertSame(0, $result->failures->count());
        $this->assertSame(IrPassStatus::Complete, $result->status);
        $this->assertSame(0, $agent->calls, 'no Keep content → agent not called');
        $this->assertNull($agent->seen);
    }

    // ─── content-failure pages: flag, do NOT design ──────────────────────

    #[Test]
    public function keep_pages_without_captured_content_become_explicit_failures_no_opus_call_for_them(): void
    {
        // Build a manifest where ONLY the home page got captured (the other
        // 15 keep pages have neither a ContentRef nor a body on disk —
        // exactly the shape of a partial extraction). IrPass must:
        //   - NOT send the 15 body-less pages to Opus
        //   - flag each with an IrPassFailure citing the missing body
        //   - still design IR for the 1 page with content
        //   - return Partial (because failures != [])
        $manifest = $this->stthomasOnlyHomeCaptured();
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $agent = new FakeIrPassAgent;
        $result = $this->makeIrPass($agent)->run($plan, $manifest);

        $this->assertSame(1, $agent->calls, 'agent called once for the one captured page');
        $this->assertSame(1, $agent->seen->keep_pages->count(), 'only the captured page is sent to Opus');
        $this->assertSame(1, $agent->seen->keep_page_bodies->count());

        $this->assertSame(IrPassStatus::Partial, $result->status);
        $this->assertCount(1, $result->pages);
        $this->assertCount(15, $result->failures);

        foreach ($result->failures as $failure) {
            /** @var IrPassFailure $failure */
            $this->assertStringContainsString('content was never captured', $failure->reason);
        }

        // Reconciliation tie-out: every keep content page is in pages OR
        // failures, exactly once.
        $pageSlugs = array_map(
            static fn (Ir $ir): string => $ir->page_slug,
            $result->pages->items(),
        );
        $failureSlugs = array_map(
            static fn (IrPassFailure $f): string => $f->page_slug,
            $result->failures->items(),
        );
        $this->assertSame(
            16,
            count($pageSlugs) + count($failureSlugs),
            'reconciliation: every keep content page must be in pages OR failures'
        );
        $this->assertSame([], array_intersect($pageSlugs, $failureSlugs), 'no page appears in both pages and failures');
    }

    #[Test]
    public function content_failure_reason_carries_underlying_ingest_failure_message(): void
    {
        // When the manifest's content_failures has an explicit failure for
        // a URL, IrPass surfaces the original reason so a reviewer can
        // tell "Firecrawl 5xx'd" from "page just wasn't captured".
        $manifest = $this->stthomasOnlyHomeCaptured();
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $agent = new FakeIrPassAgent;
        $result = $this->makeIrPass($agent)->run($plan, $manifest);

        $reasons = array_map(
            static fn (IrPassFailure $f): string => $f->reason,
            $result->failures->items(),
        );
        $withIngestReason = array_filter(
            $reasons,
            static fn (string $r): bool => str_contains($r, 'ingest failure: firecrawl_returned_null'),
        );
        $this->assertNotEmpty(
            $withIngestReason,
            'failures should preserve the upstream ContentExtractionFailure.reason'
        );
    }

    #[Test]
    public function every_keep_content_page_is_in_pages_or_failures_never_silently_absent(): void
    {
        // Stronger tie-out than the above: enumerate every kept_pages
        // node with kind=page and Keep, confirm we account for ALL of them
        // across pages + failures with no gaps.
        $manifest = $this->stthomasOnlyHomeCaptured();
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $expectedSlugs = [];
        /** @var array<int, InventoryPage> $pages */
        $pages = $plan->kept_pages->items();
        foreach ($pages as $p) {
            if ($p->kind === 'page') {
                $expectedSlugs[] = PageSlug::of($p);
            }
        }
        sort($expectedSlugs);

        $agent = new FakeIrPassAgent;
        $result = $this->makeIrPass($agent)->run($plan, $manifest);

        $actualSlugs = array_merge(
            array_map(static fn (Ir $ir): string => $ir->page_slug, $result->pages->items()),
            array_map(static fn (IrPassFailure $f): string => $f->page_slug, $result->failures->items()),
        );
        sort($actualSlugs);

        $this->assertSame($expectedSlugs, $actualSlugs);
    }

    // ─── single-call capacity guard: FAIL LOUDLY ─────────────────────────

    #[Test]
    public function exceeding_single_call_page_limit_fails_loudly_with_no_opus_call(): void
    {
        // Build a synthetic SitePlan with 26 keep content pages (one over
        // the single-call limit). IrPass must abort BEFORE any agent call,
        // mark the result Failed, and emit one IrPassFailure per page with
        // the over-capacity reason. NO truncation to the first 25.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = $this->planWithSyntheticKeepPages(IrPass::SINGLE_CALL_PAGE_LIMIT + 1);

        $agent = new FakeIrPassAgent;
        $result = $this->makeIrPass($agent)->run($plan, $manifest);

        $this->assertSame(0, $agent->calls, 'over-capacity: no Opus call burned');
        $this->assertSame(IrPassStatus::Failed, $result->status);
        $this->assertCount(0, $result->pages);
        $this->assertCount(IrPass::SINGLE_CALL_PAGE_LIMIT + 1, $result->failures);

        foreach ($result->failures as $failure) {
            /** @var IrPassFailure $failure */
            $this->assertStringContainsString('single-call IR capacity', $failure->reason);
            $this->assertStringContainsString('chunking not yet implemented', $failure->reason);
        }
    }

    #[Test]
    public function at_single_call_page_limit_still_runs_normally(): void
    {
        // Boundary: exactly SINGLE_CALL_PAGE_LIMIT pages is fine. The
        // guard fires only on EXCEEDING. (The default responder synthesises
        // an IR per keep page, so a Complete result here proves the guard
        // didn't false-fire at the boundary.)
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = $this->planWithSyntheticKeepPages(IrPass::SINGLE_CALL_PAGE_LIMIT);

        // The synthetic pages won't have ContentRefs in the manifest, so
        // they'd all become content failures. That's fine for the guard
        // test — the point is status != Failed (the over-capacity branch
        // didn't fire). We expect status=Partial (every page failed for
        // missing content), agent never called (no designable pages).
        $agent = new FakeIrPassAgent;
        $result = $this->makeIrPass($agent)->run($plan, $manifest);

        $this->assertNotSame(IrPassStatus::Failed, $result->status, 'guard must not false-fire at the boundary');
    }

    // ─── validate-then-targeted-retry-then-flag (unchanged behaviour) ────

    #[Test]
    public function targeted_retry_fires_only_for_missing_pages_and_recovers_them(): void
    {
        // Fake drops two specific pages on the first call (Coaches, Board)
        // — the same pages real Opus dropped on the original St. Thomas run.
        // On the targeted retry, the fake returns them. Final result is
        // Complete; both calls were made; the retry input contained ONLY
        // the two missing pages.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $coachesId = 2901075;
        $boardId = 2901074;
        $missingSlugs = ['page-'.$coachesId, 'page-'.$boardId];

        $callIndex = 0;
        $agent = new FakeIrPassAgent;
        $agent->respondWith(function (IrPassInput $input) use (&$callIndex, $missingSlugs): IrPassAgentResponse {
            $callIndex++;

            /** @var array<int, Ir> $pages */
            $pages = [];
            /** @var array<int, InventoryPage> $keep */
            $keep = $input->keep_pages->items();
            foreach ($keep as $i => $page) {
                $slug = PageSlug::of($page);
                if ($callIndex === 1 && in_array($slug, $missingSlugs, true)) {
                    continue;
                }
                $pages[] = new Ir(
                    page_slug: $slug,
                    page_title: $page->label,
                    nav_order: $i,
                    blocks: new DataCollection(IrBlock::class, [
                        new IrBlock(component_type: 'hero', content_brief: 'h'),
                    ]),
                );
            }

            return new IrPassAgentResponse(
                style_brief: new GlobalStyleBrief(
                    brand_voice: 'v',
                    palette: ['primary' => '#000'],
                    layout_conventions: ['c'],
                    nav: $input->nav,
                ),
                pages: new DataCollection(Ir::class, $pages),
            );
        });

        $result = $this->makeIrPass($agent)->run($plan, $manifest);

        $this->assertSame(2, $agent->calls, 'one initial call + one retry');

        $retryInput = $agent->allSeen[1];
        $this->assertSame(2, $retryInput->keep_pages->count());
        $this->assertSame(2, $retryInput->keep_page_bodies->count(), 'retry bodies align with retry pages');
        $retrySlugs = array_map(
            static fn (InventoryPage $p): string => PageSlug::of($p),
            $retryInput->keep_pages->items(),
        );
        sort($retrySlugs);
        sort($missingSlugs);
        $this->assertSame($missingSlugs, $retrySlugs);

        // Full nav passed to the retry so the model can place the missing
        // pages in context even though only 2 are being designed.
        $this->assertSame($plan->nav->count(), $retryInput->nav->count());

        $this->assertCount(16, $result->pages);
        $this->assertCount(0, $result->failures);
        $this->assertSame(IrPassStatus::Complete, $result->status);

        $returnedSlugs = array_map(
            static fn (Ir $ir): string => $ir->page_slug,
            $result->pages->items(),
        );
        foreach ($missingSlugs as $expected) {
            $this->assertContains($expected, $returnedSlugs);
        }
    }

    #[Test]
    public function still_missing_after_retry_becomes_explicit_failure_status_partial(): void
    {
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $coachesId = 2901075;
        $boardId = 2901074;
        $persistentlyMissing = ['page-'.$coachesId, 'page-'.$boardId];

        $agent = new FakeIrPassAgent;
        $agent->respondWith(function (IrPassInput $input) use ($persistentlyMissing): IrPassAgentResponse {
            /** @var array<int, Ir> $pages */
            $pages = [];
            /** @var array<int, InventoryPage> $keep */
            $keep = $input->keep_pages->items();
            foreach ($keep as $i => $page) {
                $slug = PageSlug::of($page);
                if (in_array($slug, $persistentlyMissing, true)) {
                    continue;
                }
                $pages[] = new Ir(
                    page_slug: $slug,
                    page_title: $page->label,
                    nav_order: $i,
                    blocks: new DataCollection(IrBlock::class, [
                        new IrBlock(component_type: 'hero', content_brief: 'h'),
                    ]),
                );
            }

            return new IrPassAgentResponse(
                style_brief: new GlobalStyleBrief(
                    brand_voice: 'v',
                    palette: ['primary' => '#000'],
                    layout_conventions: ['c'],
                    nav: $input->nav,
                ),
                pages: new DataCollection(Ir::class, $pages),
            );
        });

        $result = $this->makeIrPass($agent)->run($plan, $manifest);

        $this->assertSame(2, $agent->calls, 'retry was attempted before flagging');
        $this->assertSame(IrPassStatus::Partial, $result->status);
        $this->assertCount(14, $result->pages);
        $this->assertCount(2, $result->failures);

        $failureSlugs = array_map(
            static fn (IrPassFailure $f): string => $f->page_slug,
            $result->failures->items(),
        );
        sort($failureSlugs);
        sort($persistentlyMissing);
        $this->assertSame($persistentlyMissing, $failureSlugs);

        foreach ($result->failures as $failure) {
            /** @var IrPassFailure $failure */
            $this->assertNotEmpty($failure->page_title);
            $this->assertNotNull($failure->page_node_id);
            $this->assertStringContainsString('targeted retry', $failure->reason);
        }

        $returnedSlugs = array_map(
            static fn (Ir $ir): string => $ir->page_slug,
            $result->pages->items(),
        );
        foreach ($persistentlyMissing as $slug) {
            $this->assertNotContains(
                $slug,
                $returnedSlugs,
                "missing page '{$slug}' must not be in pages collection (would be a stub)"
            );
        }

        foreach ($result->pages as $ir) {
            /** @var Ir $ir */
            $this->assertGreaterThan(0, $ir->blocks->count());
            foreach ($ir->blocks as $block) {
                /** @var IrBlock $block */
                $this->assertNotEmpty($block->component_type);
                $this->assertNotEmpty($block->content_brief);
            }
        }
    }

    #[Test]
    public function retry_recovering_some_but_not_all_yields_partial_with_only_unrecovered_failures(): void
    {
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $coachesId = 2901075;
        $boardId = 2901074;
        $facilitiesId = 2901076;
        $dropOnFirst = ['page-'.$coachesId, 'page-'.$boardId, 'page-'.$facilitiesId];
        $dropOnRetry = ['page-'.$coachesId, 'page-'.$boardId];

        $callIndex = 0;
        $agent = new FakeIrPassAgent;
        $agent->respondWith(function (IrPassInput $input) use (&$callIndex, $dropOnFirst, $dropOnRetry): IrPassAgentResponse {
            $callIndex++;
            $skip = $callIndex === 1 ? $dropOnFirst : $dropOnRetry;

            /** @var array<int, Ir> $pages */
            $pages = [];
            /** @var array<int, InventoryPage> $keep */
            $keep = $input->keep_pages->items();
            foreach ($keep as $i => $page) {
                $slug = PageSlug::of($page);
                if (in_array($slug, $skip, true)) {
                    continue;
                }
                $pages[] = new Ir(
                    page_slug: $slug,
                    page_title: $page->label,
                    nav_order: $i,
                    blocks: new DataCollection(IrBlock::class, [
                        new IrBlock(component_type: 'hero', content_brief: 'h'),
                    ]),
                );
            }

            return new IrPassAgentResponse(
                style_brief: new GlobalStyleBrief(
                    brand_voice: 'v',
                    palette: ['primary' => '#000'],
                    layout_conventions: ['c'],
                    nav: $input->nav,
                ),
                pages: new DataCollection(Ir::class, $pages),
            );
        });

        $result = $this->makeIrPass($agent)->run($plan, $manifest);

        $this->assertSame(2, $agent->calls);
        $this->assertCount(14, $result->pages);
        $this->assertCount(2, $result->failures);
        $this->assertSame(IrPassStatus::Partial, $result->status);

        $failureSlugs = array_map(
            static fn (IrPassFailure $f): string => $f->page_slug,
            $result->failures->items(),
        );
        sort($failureSlugs);
        sort($dropOnRetry);
        $this->assertSame($dropOnRetry, $failureSlugs, 'failures = ONLY those missing after retry');

        $returnedSlugs = array_map(
            static fn (Ir $ir): string => $ir->page_slug,
            $result->pages->items(),
        );
        $this->assertContains('page-'.$facilitiesId, $returnedSlugs);
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * Stthomas Manifest where only the home page got captured. Every other
     * keep page has an explicit ContentExtractionFailure (firecrawl_returned_null).
     */
    private function stthomasOnlyHomeCaptured(): Manifest
    {
        // Build with the default-echo OFF so unpreloaded URLs return null
        // and become ContentExtractionFailures — exactly the shape of a
        // partial-extraction Manifest from production.
        $html = new FixtureHtmlFetcher;
        $html->preloadFromFile(
            requestedUrl: 'https://www.stthomassoccer.com/',
            finalUrl: 'https://www.stthomassoccer.com/',
            path: __DIR__.'/../../Fixtures/rootnav/real/stthomassoccer.homepage.html',
        );

        $nav = new FixtureRootNavFetcher;
        $nav->preloadFromFile(2901070, __DIR__.'/../../Fixtures/rootnav/real/stthomassoccer.rootnav.json');
        $nav->preloadFromFile(2901073, __DIR__.'/../../Fixtures/rootnav/real/stthomassoccer.node.2901073.json');

        $firecrawl = new FakeFirecrawlClient;
        // Only the home URL is preloaded; everything else returns null →
        // becomes a ContentExtractionFailure on the Manifest.
        $firecrawl->preload(
            'https://www.stthomassoccer.com/page/show/2901070-home',
            new ScrapedPage(
                url: 'https://www.stthomassoccer.com/page/show/2901070-home',
                title: 'Home',
                markdown: '# Home',
                html: '<h1>Home</h1>',
            ),
        );

        $uploader = new S3AssetUploader(disk: self::DISK);
        $extractor = new SportNginExtractor(
            $html,
            $nav,
            $firecrawl,
            $uploader,
            new BrandExtractor,
            new SeCdnRehoster($uploader),
        );

        return $extractor->extract('https://www.stthomassoccer.com/');
    }

    /**
     * SitePlan with $count synthetic keep content pages — used by the
     * single-call capacity guard tests. Nav/ledger are minimal but valid
     * (every keep page has a matching Keep ledger entry, otherwise
     * extractKeepContentPages would filter them out).
     */
    private function planWithSyntheticKeepPages(int $count): SitePlan
    {
        /** @var array<int, InventoryPage> $pages */
        $pages = [];
        /** @var array<int, NavItem> $navItems */
        $navItems = [];
        /** @var array<int, DecisionEntry> $entries */
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $url = "https://example.test/page-{$i}";
            $page = new InventoryPage(
                label: "Page {$i}",
                url: $url,
                kind: 'page',
                node_type: 'Page',
                page_node_id: 9000000 + $i,
                external_subtype: null,
                depth: 1,
                nav_path: [],
                has_children: false,
            );
            $pages[] = $page;
            $navItems[] = new NavItem(
                label: $page->label,
                page_slug: PageSlug::of($page),
                order: $i,
            );
            $entries[] = new DecisionEntry(
                target: $url,
                action: DecisionAction::Keep,
                reason: 'synthetic keep',
                confidence: 1.0,
            );
        }

        return new SitePlan(
            nav: new DataCollection(NavItem::class, $navItems),
            kept_pages: new DataCollection(InventoryPage::class, $pages),
            ledger: new DecisionLedger(
                entries: new DataCollection(DecisionEntry::class, $entries),
            ),
        );
    }
}
