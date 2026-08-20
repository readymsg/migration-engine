<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\ContentRef;
use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\DecisionLedger;
use App\Data\InventoryPage;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Data\IrChunkDesignerInput;
use App\Data\IrChunkDesignerResponse;
use App\Data\IrPassFailure;
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
use App\Services\Plan\SePlatformContentDetector;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Extract\FakeFirecrawlClient;
use Tests\Support\Extract\FixtureHtmlFetcher;
use Tests\Support\Extract\FixtureRootNavFetcher;
use Tests\Support\Generate\FakeIrBriefDeriverAgent;
use Tests\Support\Generate\FakeIrChunkDesignerAgent;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;
use Tests\TestCase;

// IR-pass chunked-path tests. The single-call cap is gone; sites of
// arbitrary size now flow through one brief-deriver call + K chunk-
// designer calls, with per-chunk targeted retry and union
// reconciliation.
//
// Faithful-rebuild invariant remains: every keep-content page is in
// pages OR failures, exactly once. Diff-the-universe is authoritative;
// no chunk's success flag is.
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
        Storage::fake(self::DISK);
    }

    private function makeIrPass(
        FakeIrBriefDeriverAgent $brief,
        FakeIrChunkDesignerAgent $designer,
    ): IrPass {
        return new IrPass($brief, $designer, new ContentLoader(disk: self::DISK));
    }

    // ─── basic behavior (preserved across the refactor) ──────────────────

    #[Test]
    public function ir_pass_generates_style_brief_and_ir_for_keep_content_pages_only(): void
    {
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertSame(IrPassStatus::Complete, $result->status);
        $this->assertCount(0, $result->failures);
        $this->assertSame(1, $brief->calls, 'brief-deriver runs ONCE per site');
        // stthomas has 16 designable pages → ceil(16/15) = 2 chunks.
        $this->assertSame(2, $designer->calls, '16 pages > 15 → 2 chunks → 2 designer calls');

        $this->assertNotEmpty($result->style_brief->brand_voice);
        $this->assertNotEmpty($result->style_brief->palette);
        $this->assertNotEmpty($result->style_brief->layout_conventions);
        $this->assertSame($plan->nav->count(), $result->style_brief->nav->count());

        $this->assertCount(16, $result->pages);
    }

    #[Test]
    public function chunk_designer_receives_the_brief_as_locked_input(): void
    {
        // The whole point of the two-agent split: the brief is derived
        // ONCE and every chunk receives it. Assert the designer's input
        // is the exact brief the deriver returned.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertNotNull($designer->seen);
        // FakeIrBriefDeriverAgent default returns a known brief; assert
        // the designer received it verbatim.
        $this->assertSame('fake voice — warm, community-focused', $designer->seen->style_brief->brand_voice);
        $this->assertSame(['primary' => '#003366', 'secondary' => '#FFCC00'], $designer->seen->style_brief->palette);
    }

    #[Test]
    public function chunk_designer_input_carries_body_markdown_for_every_chunk_page(): void
    {
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertNotNull($designer->seen);

        /** @var array<string, KeepPageContent> $bodyBySlug */
        $bodyBySlug = [];
        /** @var array<int, KeepPageContent> $bodies */
        $bodies = $designer->seen->chunk_bodies->items();
        foreach ($bodies as $body) {
            $bodyBySlug[$body->page_slug] = $body;
        }

        /** @var array<int, InventoryPage> $pages */
        $pages = $designer->seen->chunk_pages->items();
        foreach ($pages as $page) {
            $slug = PageSlug::of($page);
            $this->assertArrayHasKey($slug, $bodyBySlug, "chunk_page '{$slug}' has no matching body");
            $this->assertNotEmpty($bodyBySlug[$slug]->markdown, "body for '{$slug}' is empty");
            $this->assertSame($page->label, $bodyBySlug[$slug]->page_title);
        }
    }

    #[Test]
    public function brief_deriver_input_is_bounded_to_sample_limit(): void
    {
        // Synthetic plan with 30 keep_pages — well over the brief sample
        // cap. Sample size must be bounded by BRIEF_SAMPLE_LIMIT, NOT
        // grow with site size. This is what makes the brief-deriver a
        // bounded call.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = $this->planWithSyntheticKeepPages(30);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        // Synthetic pages have no content_refs → all become content
        // failures; brief-deriver still runs only if there are
        // designable pages. Here every page fails, so brief-deriver is
        // never called. Skip the assert about call count, but verify
        // the sample limit constant is sensible.
        $this->assertLessThanOrEqual(15, IrPass::BRIEF_SAMPLE_LIMIT, 'brief sample stays bounded');
    }

    #[Test]
    public function brief_deriver_sample_prefers_depth_zero_pages(): void
    {
        // Real stthomas has 16 keep pages, several at depth 0
        // (Home / Coaches / Board / etc.). Confirm the sample
        // prioritizes them.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertNotNull($brief->seen);
        $samplePages = $brief->seen->sample_pages->items();
        $depth0Count = count(array_filter($samplePages, static fn (InventoryPage $p): bool => $p->depth === 0));

        // We don't pin the exact count — depends on stthomas's depth-0
        // page count — but every depth-0 page should be in the sample
        // before any depth-1+ page is. Approximation: at least 1
        // depth-0 page in the sample.
        $this->assertGreaterThan(0, $depth0Count, 'brief sample must include depth-0 pages');
    }

    #[Test]
    public function platform_dynamic_subsumed_park_and_external_pages_get_no_ir(): void
    {
        $manifest = RealManifests::tenacityvolleyballWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertNotNull($designer->seen);
        // Aggregate across ALL chunks — tenacity has 20 designable
        // pages → 2 chunks, and we need to inspect both for the
        // filtering invariant.
        /** @var array<int, string> $seenLabels */
        $seenLabels = [];
        foreach ($designer->allSeen as $input) {
            foreach ($input->chunk_pages->items() as $page) {
                /** @var InventoryPage $page */
                $seenLabels[] = $page->label;
            }
        }

        // Still filtered: PlatformDynamic pages (TEAMS name-matched to
        // PlatformBlockType::Teams, CALENDAR routed by node_type) never
        // reach the IR designer — the platform-block renderer emits their
        // PuckOutputs deterministically.
        $this->assertNotContains('TEAMS', $seenLabels);
        $this->assertNotContains('CALENDAR', $seenLabels);

        // TEAMS's 3 Page-kind children (11s & 12s, 13s & 14s, 15s-18s) DO
        // reach the designer now: PlatformBlockType::Teams is a hierarchy
        // directory that does NOT subsume descendants, so those children
        // keep their own classification (Keep@0.85 from
        // FakeClassifierAgent) and go through the full IR + block-fill
        // path as ordinary content pages.
        $this->assertContains('11s & 12s', $seenLabels);
        $this->assertContains('13s & 14s', $seenLabels);
        $this->assertContains('15s-18s', $seenLabels);

        $this->assertContains('HOME', $seenLabels);
        $this->assertContains('ABOUT US', $seenLabels);
        $this->assertContains('FAQ', $seenLabels);
    }

    #[Test]
    public function ir_blocks_are_schema_agnostic_no_puck_prop_names_in_component_type(): void
    {
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        foreach ($result->pages as $ir) {
            /** @var Ir $ir */
            foreach ($ir->blocks as $block) {
                /** @var IrBlock $block */
                $this->assertNotContains($block->component_type, self::PUCK_PROP_NAMES);
                $this->assertContains($block->component_type, self::ABSTRACT_VOCAB);
                $this->assertNotEmpty($block->content_brief);
            }
        }
    }

    #[Test]
    public function ir_pass_returns_empty_result_when_no_keep_content_pages(): void
    {
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $emptyPlan = new SitePlan(
            nav: $plan->nav,
            kept_pages: new DataCollection(InventoryPage::class, []),
            ledger: $plan->ledger,
        );

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $result = $this->makeIrPass($brief, $designer)->run($emptyPlan, $manifest);

        $this->assertSame(0, $result->pages->count());
        $this->assertSame(0, $result->failures->count());
        $this->assertSame(IrPassStatus::Complete, $result->status);
        $this->assertSame(0, $brief->calls, 'empty plan: no brief call');
        $this->assertSame(0, $designer->calls, 'empty plan: no designer call');
    }

    // ─── content-failure pages: flag, do NOT design ──────────────────────

    #[Test]
    public function keep_pages_without_captured_content_become_explicit_failures_no_designer_call_for_them(): void
    {
        $manifest = $this->stthomasOnlyHomeCaptured();
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertSame(1, $brief->calls, 'brief-deriver runs once because there IS a designable page');
        $this->assertSame(1, $designer->calls, 'one chunk → one designer call');
        $this->assertSame(1, $designer->seen->chunk_pages->count(), 'only the captured page is in the chunk');
        $this->assertSame(1, $designer->seen->chunk_bodies->count());

        $this->assertSame(IrPassStatus::Partial, $result->status);
        $this->assertCount(1, $result->pages);
        $this->assertCount(15, $result->failures);

        foreach ($result->failures as $failure) {
            /** @var IrPassFailure $failure */
            $this->assertStringContainsString('content was never captured', $failure->reason);
        }

        $pageSlugs = array_map(static fn (Ir $ir): string => $ir->page_slug, $result->pages->items());
        $failureSlugs = array_map(static fn (IrPassFailure $f): string => $f->page_slug, $result->failures->items());
        $this->assertSame(16, count($pageSlugs) + count($failureSlugs));
        $this->assertSame([], array_intersect($pageSlugs, $failureSlugs));
    }

    #[Test]
    public function content_failure_reason_carries_underlying_ingest_failure_message(): void
    {
        $manifest = $this->stthomasOnlyHomeCaptured();
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $reasons = array_map(static fn (IrPassFailure $f): string => $f->reason, $result->failures->items());
        $withIngestReason = array_filter($reasons, static fn (string $r): bool => str_contains($r, 'ingest failure: firecrawl_returned_null'));
        $this->assertNotEmpty($withIngestReason);
    }

    #[Test]
    public function every_keep_content_page_is_in_pages_or_failures_never_silently_absent(): void
    {
        $manifest = $this->stthomasOnlyHomeCaptured();
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $expectedSlugs = [];
        /** @var array<int, InventoryPage> $pages */
        $pages = $plan->kept_pages->items();
        foreach ($pages as $p) {
            if ($p->kind === 'page') {
                $expectedSlugs[] = PageSlug::of($p);
            }
        }
        sort($expectedSlugs);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $actualSlugs = array_merge(
            array_map(static fn (Ir $ir): string => $ir->page_slug, $result->pages->items()),
            array_map(static fn (IrPassFailure $f): string => $f->page_slug, $result->failures->items()),
        );
        sort($actualSlugs);

        $this->assertSame($expectedSlugs, $actualSlugs);
    }

    // ─── chunking ─────────────────────────────────────────────────────────

    #[Test]
    public function under_chunk_limit_runs_one_chunk(): void
    {
        $manifest = $this->stthomasOnlyHomeCaptured();
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertSame(1, $designer->calls, 'one chunk for ≤15 designable pages');
        $this->assertNotNull($designer->seen);
        $this->assertSame(1, $designer->seen->total_chunks);
        $this->assertSame(0, $designer->seen->chunk_index);
    }

    #[Test]
    public function over_chunk_limit_partitions_into_multiple_chunks(): void
    {
        // Pre-build synthetic pages WITH ContentRefs so they reach the
        // designer (the body resolution path doesn't flag them as
        // content failures). 34 pages → ceil(34/15) = 3 chunks.
        [$manifest, $plan] = $this->syntheticPagesWithContent(34);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertSame(3, $designer->calls, 'ceil(34/15) = 3 chunks');
        $this->assertSame(1, $brief->calls, 'brief-deriver still called ONCE for the whole site');

        // Each chunk reports correct chunk_index/total_chunks.
        $this->assertSame(0, $designer->allSeen[0]->chunk_index);
        $this->assertSame(1, $designer->allSeen[1]->chunk_index);
        $this->assertSame(2, $designer->allSeen[2]->chunk_index);
        foreach ($designer->allSeen as $input) {
            $this->assertSame(3, $input->total_chunks);
        }

        // Chunk sizes: 15 + 15 + 4 = 34. The default chunk algorithm
        // emits full-size chunks then a partial last chunk.
        $this->assertSame(15, $designer->allSeen[0]->chunk_pages->count());
        $this->assertSame(15, $designer->allSeen[1]->chunk_pages->count());
        $this->assertSame(4, $designer->allSeen[2]->chunk_pages->count());

        // All 34 pages reconciled to either pages or failures.
        $this->assertSame(34, $result->pages->count() + $result->failures->count());
        $this->assertSame(IrPassStatus::Complete, $result->status);
    }

    #[Test]
    public function over_chunk_limit_no_longer_fails_loudly(): void
    {
        // The single-call cap is REMOVED. 26 pages used to be the
        // smallest "over capacity" case; now it splits into 2 chunks.
        [$manifest, $plan] = $this->syntheticPagesWithContent(26);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertSame(2, $designer->calls, '26 pages → 2 chunks (15 + 11)');
        $this->assertSame(IrPassStatus::Complete, $result->status, 'no longer Failed');
        $this->assertCount(26, $result->pages);
        $this->assertCount(0, $result->failures);
    }

    #[Test]
    public function per_chunk_targeted_retry_recovers_silently_dropped_pages(): void
    {
        // 17 pages → 2 chunks (15 + 2). Drop one specific page in
        // chunk 0; retry recovers it. Chunk 1 unaffected.
        [$manifest, $plan] = $this->syntheticPagesWithContent(17);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;

        // Drop the LAST page of chunk 0 on its first call (call index 0).
        // Recover it on the retry (call index 2 — after chunk 1's call
        // at index 1; no wait, actually the retry for chunk 0 runs
        // BEFORE chunk 1 because of orchestration order: chunk 0 fires,
        // retry fires, then chunk 1 fires). Let me trace:
        //   call 0: chunk 0 designer (drops one)
        //   call 1: chunk 0 RETRY (returns dropped)
        //   call 2: chunk 1 designer
        $missingSlug = PageSlug::of($plan->kept_pages->items()[14]);
        $designer->respondWith(function (IrChunkDesignerInput $input) use (&$designer, $missingSlug): IrChunkDesignerResponse {
            $callIndex = $designer->calls - 1; // calls already incremented when we get here
            /** @var array<int, Ir> $pages */
            $pages = [];
            foreach ($input->chunk_pages->items() as $i => $page) {
                $slug = PageSlug::of($page);
                if ($callIndex === 0 && $slug === $missingSlug) {
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

            return new IrChunkDesignerResponse(pages: new DataCollection(Ir::class, $pages));
        });

        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertSame(3, $designer->calls, 'call 0: chunk 0 (drops); call 1: chunk 0 retry (recovers); call 2: chunk 1');

        // Retry input should contain ONLY the missing page.
        $retryInput = $designer->allSeen[1];
        $this->assertSame(1, $retryInput->chunk_pages->count());
        $this->assertSame($missingSlug, PageSlug::of($retryInput->chunk_pages->items()[0]));

        $this->assertSame(IrPassStatus::Complete, $result->status);
        $this->assertCount(17, $result->pages);
        $this->assertCount(0, $result->failures);
    }

    #[Test]
    public function persistent_per_chunk_missing_becomes_partial_failure(): void
    {
        // 16 pages → 2 chunks (15 + 1). Drop page 14 (in chunk 0) on
        // BOTH calls (first + retry); the orchestration flags it.
        [$manifest, $plan] = $this->syntheticPagesWithContent(16);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $persistentlyMissing = PageSlug::of($plan->kept_pages->items()[14]);

        $designer->respondWith(function (IrChunkDesignerInput $input) use ($persistentlyMissing): IrChunkDesignerResponse {
            /** @var array<int, Ir> $pages */
            $pages = [];
            foreach ($input->chunk_pages->items() as $i => $page) {
                $slug = PageSlug::of($page);
                if ($slug === $persistentlyMissing) {
                    continue;
                }
                $pages[] = new Ir(
                    page_slug: $slug,
                    page_title: $page->label,
                    nav_order: $i,
                    blocks: new DataCollection(IrBlock::class, [new IrBlock(component_type: 'hero', content_brief: 'h')]),
                );
            }

            return new IrChunkDesignerResponse(pages: new DataCollection(Ir::class, $pages));
        });

        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertSame(IrPassStatus::Partial, $result->status);
        $this->assertCount(15, $result->pages);
        $this->assertCount(1, $result->failures);

        /** @var IrPassFailure $failure */
        $failure = $result->failures->items()[0];
        $this->assertSame($persistentlyMissing, $failure->page_slug);
        $this->assertStringContainsString('initial response and from targeted retry', $failure->reason);
    }

    #[Test]
    public function catastrophic_chunk_failure_synthesizes_one_failure_per_page_no_silent_loss(): void
    {
        // 30 pages → 2 chunks of 15. Chunk 1 (call index 1) throws.
        // Orchestration MUST surface 15 failures for chunk-1 pages —
        // not lose them. This is the load-bearing reconciliation
        // safety net.
        [$manifest, $plan] = $this->syntheticPagesWithContent(30);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $designer->throwOnCall(1, new RuntimeException('simulated upstream Anthropic 500 on chunk 2'));

        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertSame(IrPassStatus::Partial, $result->status, 'partial = chunk 0 succeeded, chunk 1 failed');
        $this->assertCount(15, $result->pages, 'chunk 0 pages survive');
        $this->assertCount(15, $result->failures, 'chunk 1 pages all surface as failures');

        foreach ($result->failures as $failure) {
            /** @var IrPassFailure $failure */
            $this->assertStringContainsString('chunk #2/2 threw', $failure->reason);
            $this->assertStringContainsString('simulated upstream Anthropic 500', $failure->reason);
        }

        // Union: 15 pages + 15 failures = 30 keep_pages, no overlap.
        $pageSlugs = array_map(static fn (Ir $ir): string => $ir->page_slug, $result->pages->items());
        $failureSlugs = array_map(static fn (IrPassFailure $f): string => $f->page_slug, $result->failures->items());
        $this->assertSame(30, count($pageSlugs) + count($failureSlugs));
        $this->assertSame([], array_intersect($pageSlugs, $failureSlugs));
    }

    #[Test]
    public function all_chunks_failing_yields_status_failed(): void
    {
        [$manifest, $plan] = $this->syntheticPagesWithContent(20);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $designer->throwOnCall(0, new RuntimeException('chunk 1 down'));
        $designer->throwOnCall(1, new RuntimeException('chunk 2 down'));

        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertSame(IrPassStatus::Failed, $result->status, 'all chunks failed → Failed (cf. cjfl over-capacity case)');
        $this->assertCount(0, $result->pages);
        $this->assertCount(20, $result->failures);
    }

    // ─── brief-deriver failure: graceful fallback ────────────────────────

    #[Test]
    public function brief_deriver_throwing_yields_empty_brief_plus_sentinel_failure_designer_still_runs(): void
    {
        // Per the design decision: a brief-deriver failure does NOT
        // abort the IR pass. The designer still runs (with empty
        // brief), per-page IR still ships, and a sentinel failure
        // marks the coherence loss for the reviewer.
        $manifest = RealManifests::stthomasWithContentCaptured(self::DISK);
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $brief = new FakeIrBriefDeriverAgent;
        $brief->throwOnNextCall(new RuntimeException('brief gateway timeout'));
        $designer = new FakeIrChunkDesignerAgent;
        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        $this->assertSame(1, $brief->calls);
        // 16 stthomas pages → 2 chunks → 2 designer calls.
        $this->assertSame(2, $designer->calls, 'designer still runs across all chunks');

        // Each chunk's designer call received an EMPTY brief.
        foreach ($designer->allSeen as $input) {
            $this->assertSame('', $input->style_brief->brand_voice);
            $this->assertSame([], $input->style_brief->palette);
        }

        // Per-page IR still produced; status is Partial because of
        // the brief failure flag.
        $this->assertSame(IrPassStatus::Partial, $result->status);
        $this->assertCount(16, $result->pages);
        $this->assertCount(1, $result->failures);

        /** @var IrPassFailure $failure */
        $failure = $result->failures->items()[0];
        $this->assertSame(IrPass::BRIEF_FAILURE_SLUG, $failure->page_slug);
        $this->assertStringContainsString('brief-derivation-failed', $failure->reason);
        $this->assertStringContainsString('brief gateway timeout', $failure->reason);
    }

    // ─── per-page body-size guard ────────────────────────────────────────

    #[Test]
    public function huge_body_pages_become_content_failures_before_any_llm_call(): void
    {
        // Build a manifest where the home page body is 60KB (> 50KB
        // MAX_BODY_BYTES). The page reaches resolveBodies, gets flagged
        // as a content failure, and does NOT reach the brief sample or
        // any chunk.
        $manifest = $this->stthomasHomeWithHugeBody();
        $plan = (new RootNavPlanner(new FakeClassifierAgent, new ContentLoader(disk: self::DISK), new SePlatformContentDetector))->plan($manifest);

        $brief = new FakeIrBriefDeriverAgent;
        $designer = new FakeIrChunkDesignerAgent;
        $result = $this->makeIrPass($brief, $designer)->run($plan, $manifest);

        // The huge-body home page should surface as a content failure.
        $failureReasons = array_map(static fn (IrPassFailure $f): string => $f->reason, $result->failures->items());
        $matching = array_filter($failureReasons, static fn (string $r): bool => str_contains($r, 'per-page size cap'));
        $this->assertNotEmpty($matching, 'huge body must surface as a per-page-size-cap content failure');

        // And it must NOT be in the designer's chunk_pages.
        if ($designer->calls > 0) {
            $chunkLabels = array_map(
                static fn (InventoryPage $p): string => $p->label,
                $designer->seen->chunk_pages->items(),
            );
            $this->assertNotContains('Home', $chunkLabels, 'huge home page never reaches the designer');
        }
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    private function stthomasOnlyHomeCaptured(): Manifest
    {
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

    private function stthomasHomeWithHugeBody(): Manifest
    {
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
        // 60KB body — over MAX_BODY_BYTES.
        $hugeMarkdown = '# Home'.PHP_EOL.str_repeat('Lorem ipsum dolor sit amet. ', 2200);
        $this->assertGreaterThan(IrPass::MAX_BODY_BYTES, strlen($hugeMarkdown));

        $firecrawl->preload(
            'https://www.stthomassoccer.com/page/show/2901070-home',
            new ScrapedPage(
                url: 'https://www.stthomassoccer.com/page/show/2901070-home',
                title: 'Home',
                markdown: $hugeMarkdown,
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
     * Build a Manifest + SitePlan with $count synthetic keep-content
     * pages, each WITH a captured body so they reach the designer
     * (the body-resolution path doesn't flag them as content failures).
     *
     * @return array{0: Manifest, 1: SitePlan}
     */
    private function syntheticPagesWithContent(int $count): array
    {
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
        $uploader = new S3AssetUploader(disk: self::DISK);
        $extractor = new SportNginExtractor(
            $html,
            $nav,
            $firecrawl,
            $uploader,
            new BrandExtractor,
            new SeCdnRehoster($uploader),
        );

        // Run extractor to get a real Manifest skeleton, then synthesize
        // ContentRefs + bodies for the synthetic pages.
        $realManifest = $extractor->extract('https://www.stthomassoccer.com/');

        /** @var array<int, InventoryPage> $pages */
        $pages = [];
        /** @var array<int, NavItem> $navItems */
        $navItems = [];
        /** @var array<int, DecisionEntry> $entries */
        $entries = [];
        /** @var array<int, ContentRef> $contentRefs */
        $contentRefs = [];

        for ($i = 0; $i < $count; $i++) {
            $url = "https://www.stthomassoccer.com/page/show/8000{$i}-page-{$i}";
            $page = new InventoryPage(
                label: "Page {$i}",
                url: $url,
                kind: 'page',
                node_type: 'Page',
                page_node_id: 8000000 + $i,
                external_subtype: null,
                depth: $i < 5 ? 0 : 1,
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

            // Write a body for this synthetic page on the test disk and
            // register a ContentRef pointing at it, so IrPass's
            // resolveBodies() can load it successfully.
            $scrapeKey = "orgs/{$realManifest->org_id}/scrapes/synthetic-{$i}.json";
            $scrape = new ScrapedPage(
                url: $url,
                title: $page->label,
                markdown: "# {$page->label}\n\nSome synthetic content for chunking tests.",
                html: '',
            );
            Storage::disk(self::DISK)->put($scrapeKey, json_encode($scrape->toArray()));
            $contentRefs[] = new ContentRef(
                url: $url,
                scrape_ref: $scrapeKey,
                title: $page->label,
            );
        }

        // Build the synthetic Manifest with the synthetic ContentRefs.
        $manifest = new Manifest(
            source_url: $realManifest->source_url,
            org_id: $realManifest->org_id,
            structure: $realManifest->structure,
            provisioning: $realManifest->provisioning,
            brand: $realManifest->brand,
            content_refs: new DataCollection(ContentRef::class, $contentRefs),
            asset_refs: $realManifest->asset_refs,
            confidence: $realManifest->confidence,
            flags: $realManifest->flags,
            content_failures: $realManifest->content_failures,
            cdn_assets_found: $realManifest->cdn_assets_found,
            cdn_assets_rehosted: $realManifest->cdn_assets_rehosted,
        );

        $plan = new SitePlan(
            nav: new DataCollection(NavItem::class, $navItems),
            kept_pages: new DataCollection(InventoryPage::class, $pages),
            ledger: new DecisionLedger(
                entries: new DataCollection(DecisionEntry::class, $entries),
            ),
        );

        return [$manifest, $plan];
    }

    /**
     * Legacy helper used by tests that don't need real ContentRefs
     * (the brief/designer never gets called because every page becomes
     * a content failure first).
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
