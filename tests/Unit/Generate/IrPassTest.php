<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\GlobalStyleBrief;
use App\Data\InventoryPage;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Data\IrPassAgentResponse;
use App\Data\IrPassFailure;
use App\Data\IrPassInput;
use App\Data\IrPassStatus;
use App\Data\SitePlan;
use App\Services\Generate\IrPass;
use App\Services\Generate\PageSlug;
use App\Services\Plan\RootNavPlanner;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Generate\FakeIrPassAgent;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;
use Tests\TestCase;

final class IrPassTest extends TestCase
{
    private const PUCK_PROP_NAMES = [
        'background_image', 'subheading', 'cta_label', 'cta_href', 'columns', 'fields',
    ];

    private const ABSTRACT_VOCAB = [
        'hero', 'heading', 'paragraph', 'image', 'cta', 'columns', 'card', 'list',
        'quote', 'gallery', 'form', 'video', 'team_grid', 'sponsor_strip',
        'social_links', 'contact_card', 'faq_list',
    ];

    #[Test]
    public function ir_pass_generates_style_brief_and_ir_for_keep_content_pages_only(): void
    {
        // St. Thomas: 18 nodes; under the default FakeClassifierAgent, 16
        // are kind=page+Keep, 1 is external+Keep (Swag/Spirit Wear), 1 is
        // external+Park (Dibs via SE-platform rule). Only the 16 reach IR.
        $manifest = RealManifests::stthomas();
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $agent = new FakeIrPassAgent;
        $result = (new IrPass($agent))->run($plan, $manifest);

        $this->assertSame(IrPassStatus::Complete, $result->status);
        $this->assertCount(0, $result->failures);
        $this->assertSame(1, $agent->calls, 'clean run = one call, no retry');

        $this->assertNotEmpty($result->style_brief->brand_voice);
        $this->assertNotEmpty($result->style_brief->palette);
        $this->assertNotEmpty($result->style_brief->layout_conventions);
        $this->assertSame($plan->nav->count(), $result->style_brief->nav->count());

        $this->assertSame(16, $agent->seen->keep_pages->count());
        foreach ($agent->seen->keep_pages as $page) {
            /** @var InventoryPage $page */
            $this->assertSame('page', $page->kind);
        }
        $this->assertCount(16, $result->pages);
    }

    #[Test]
    public function platform_dynamic_subsumed_park_and_external_pages_get_no_ir(): void
    {
        $manifest = RealManifests::tenacityvolleyball();
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $agent = new FakeIrPassAgent;
        (new IrPass($agent))->run($plan, $manifest);

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
        $manifest = RealManifests::stthomas();
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $agent = new FakeIrPassAgent;
        $result = (new IrPass($agent))->run($plan, $manifest);

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
        $manifest = RealManifests::stthomas();
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $emptyPlan = new SitePlan(
            nav: $plan->nav,
            kept_pages: new DataCollection(InventoryPage::class, []),
            ledger: $plan->ledger,
        );

        $agent = new FakeIrPassAgent;
        $result = (new IrPass($agent))->run($emptyPlan, $manifest);

        $this->assertSame(0, $result->pages->count());
        $this->assertSame(0, $result->failures->count());
        $this->assertSame(IrPassStatus::Complete, $result->status);
        $this->assertSame(0, $agent->calls, 'no Keep content → agent not called');
        $this->assertNull($agent->seen);
    }

    // ─── validate-then-targeted-retry-then-flag ──────────────────────────

    #[Test]
    public function targeted_retry_fires_only_for_missing_pages_and_recovers_them(): void
    {
        // Fake drops two specific pages on the first call (Coaches, Board)
        // — the same pages real Opus dropped on the original St. Thomas run.
        // On the targeted retry, the fake returns them. Final result is
        // Complete; both calls were made; the retry input contained ONLY
        // the two missing pages.
        $manifest = RealManifests::stthomas();
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
                    // Drop on first call — simulate Opus silent loss.
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

        $result = (new IrPass($agent))->run($plan, $manifest);

        $this->assertSame(2, $agent->calls, 'one initial call + one retry');

        // The retry input contained EXACTLY the two missing pages — not the
        // whole batch again, not zero, not extras.
        $retryInput = $agent->allSeen[1];
        $this->assertSame(2, $retryInput->keep_pages->count());
        $retrySlugs = array_map(
            static fn (InventoryPage $p): string => PageSlug::of($p),
            $retryInput->keep_pages->items(),
        );
        sort($retrySlugs);
        sort($missingSlugs);
        $this->assertSame($missingSlugs, $retrySlugs);

        // The full nav was still passed to the retry for context — even
        // though only 2 pages are being designed, the model should see the
        // whole site to place them.
        $this->assertSame($plan->nav->count(), $retryInput->nav->count());

        // Final result: all 16 pages, no failures, status Complete.
        $this->assertCount(16, $result->pages);
        $this->assertCount(0, $result->failures);
        $this->assertSame(IrPassStatus::Complete, $result->status);

        // The recovered pages are real Ir entries with real blocks, NOT stubs.
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
        // Fake drops the same two pages on EVERY call — they're still
        // missing after the retry. Faithful-rebuild guarantee: those pages
        // are NOT silently absent and NOT replaced with stubs; they get
        // explicit IrPassFailure entries and the result status becomes
        // Partial so the ConversionLog can surface them.
        $manifest = RealManifests::stthomas();
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
                    continue; // drop on every call
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

        $result = (new IrPass($agent))->run($plan, $manifest);

        $this->assertSame(2, $agent->calls, 'retry was attempted before flagging');

        // Status: Partial.
        $this->assertSame(IrPassStatus::Partial, $result->status);

        // 14 pages came back, 2 are explicit failures (NOT silently absent).
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
            $this->assertNotEmpty($failure->page_title, 'failure title preserved for debugging');
            $this->assertNotNull($failure->page_node_id);
            $this->assertStringContainsString('targeted retry', $failure->reason);
        }

        // NO stubs in the pages collection. Every Ir is a real one returned
        // by the agent — the failed pages are absent from pages and present
        // only in failures.
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

        // Every Ir entry has real blocks — no placeholder/stub IR ever
        // synthesized by the orchestration.
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
        // Three pages dropped on the first call; the retry recovers one but
        // still misses the other two. Final state: Partial with two
        // explicit failures, and the recovered page is in pages collection.
        $manifest = RealManifests::stthomas();
        $plan = (new RootNavPlanner(new FakeClassifierAgent))->plan($manifest);

        $coachesId = 2901075;
        $boardId = 2901074;
        $facilitiesId = 2901076;
        $dropOnFirst = ['page-'.$coachesId, 'page-'.$boardId, 'page-'.$facilitiesId];
        $dropOnRetry = ['page-'.$coachesId, 'page-'.$boardId]; // Facilities recovers; others don't

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

        $result = (new IrPass($agent))->run($plan, $manifest);

        $this->assertSame(2, $agent->calls);

        // 13 returned + 2 still missing → 15 accounted for? No — 15 from
        // first + 1 from retry = 16 total expected. 13 + 1 = 14 returned;
        // 2 in failures. 14 + 2 = 16 ✓.
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

        // The recovered Facilities page IS in pages collection.
        $returnedSlugs = array_map(
            static fn (Ir $ir): string => $ir->page_slug,
            $result->pages->items(),
        );
        $this->assertContains('page-'.$facilitiesId, $returnedSlugs);
    }
}
