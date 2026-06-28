<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\PlatformBlockType;
use App\Data\PlatformRenderStatus;
use App\Data\PuckOutput;
use App\Services\Generate\ContentLoader;
use App\Services\Generate\PlatformBlockRenderer;
use App\Services\Plan\RootNavPlanner;
use App\Services\Plan\SePlatformContentDetector;
use App\Services\Schema\DefaultPuckComponentSchema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;
use Tests\TestCase;

// Fixture replay for slice 2e: runs PLAN (with FakeClassifierAgent so
// no LLM call) + PlatformBlockRenderer against the real rootNav
// fixtures and asserts the platform PuckOutputs we expect.
//
// Two cases:
//   - tenacityvolleyball: has TEAMS (name-matched → PlatformBlockType::Teams)
//     and CALENDAR (Calendar node_type → PlatformBlockType::Calendar).
//     Renderer should emit two PuckOutputs.
//   - tbirdhoops: offline fixtures carry no deterministic platform_dynamic
//     pages and FakeClassifierAgent keeps every ambiguous page, so the
//     renderer correctly emits ZERO platform pages — proving "no
//     phantom-render on non-platform_dynamic pages". This is a RENDERER
//     correctness signal, NOT a claim that tbirdhoops production has no
//     platform content: a live PLAN run (real Haiku) might LLM-classify
//     pages that the offline fake keeps.
final class PlatformBlockRendererFixtureReplayTest extends TestCase
{
    private function planner(FakeClassifierAgent $agent): RootNavPlanner
    {
        return new RootNavPlanner(
            $agent,
            new ContentLoader(disk: 'local'),
            new SePlatformContentDetector,
        );
    }

    private function renderer(): PlatformBlockRenderer
    {
        return new PlatformBlockRenderer(new DefaultPuckComponentSchema);
    }

    #[Test]
    public function tenacityvolleyball_renders_teams_and_calendar_platform_blocks(): void
    {
        $manifest = RealManifests::tenacityvolleyball();
        $plan = $this->planner(new FakeClassifierAgent)->plan($manifest);

        // Sanity: PLAN produces exactly the two PlatformDynamic entries we
        // expect from real rootNav (TEAMS via name-map, CALENDAR via
        // node_type). If a future PLAN change adds or removes one, this
        // test goes red BEFORE the renderer assertions, making the
        // upstream-vs-downstream cause obvious.
        $platformLedgerEntries = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => $e->action === DecisionAction::PlatformDynamic,
        ));
        $this->assertCount(2, $platformLedgerEntries, 'tenacityvolleyball SitePlan should have 2 platform_dynamic entries (TEAMS, CALENDAR)');

        $result = $this->renderer()->run($plan, $manifest);

        $this->assertSame(PlatformRenderStatus::Complete, $result->status);
        $this->assertCount(2, $result->pages);
        $this->assertCount(0, $result->failures);

        /** @var array<int, PuckOutput> $pages */
        $pages = $result->pages->items();
        $byType = [];
        foreach ($pages as $page) {
            $byType[$page->content[0]['type']] = $page;
        }

        // PlatformTeams page — TEAMS top-level, has 3 subsumed children
        // (11s & 12s, 13s & 14s, 15s-18s); the renderer NEVER touches the
        // subsumed entries, only the platform_dynamic parent.
        $this->assertArrayHasKey('PlatformTeams', $byType, 'TEAMS must render as PlatformTeams');
        $teams = $byType['PlatformTeams'];
        $this->assertSame('page-8116200', $teams->page_slug);
        $this->assertSame('TEAMS', $teams->root['title']);
        $this->assertCount(1, $teams->content);
        $this->assertSame('PlatformTeams', $teams->content[0]['type']);
        $this->assertSame(['org_id' => $manifest->org_id], $teams->content[0]['props']);
        $this->assertSame([], $teams->zones);

        // PlatformCalendar page — CALENDAR is a Calendar node_type, routed
        // deterministically (never reaches the LLM).
        $this->assertArrayHasKey('PlatformCalendar', $byType, 'CALENDAR must render as PlatformCalendar');
        $calendar = $byType['PlatformCalendar'];
        $this->assertSame('page-8115918', $calendar->page_slug);
        $this->assertSame('CALENDAR', $calendar->root['title']);
        $this->assertCount(1, $calendar->content);
        $this->assertSame('PlatformCalendar', $calendar->content[0]['type']);
        $this->assertSame(['org_id' => $manifest->org_id], $calendar->content[0]['props']);

        // Faithful-rebuild guarantee: every PlatformDynamic ledger entry
        // accounted for exactly once across pages + failures.
        $renderedSlugs = array_map(static fn (PuckOutput $p): string => $p->page_slug, $pages);
        $this->assertSame(['page-8116200', 'page-8115918'], $renderedSlugs);
    }

    #[Test]
    public function tenacityvolleyball_teams_subsumed_descendants_never_render_as_platform_blocks(): void
    {
        // Pointed assertion: the 3 children of TEAMS are subsumed by PLAN
        // and present in the ledger with action=Subsumed. The renderer
        // ONLY looks at PlatformDynamic entries, so subsumed pages never
        // produce a PuckOutput. This is what keeps the rebuild from
        // double-counting subtrees.
        $manifest = RealManifests::tenacityvolleyball();
        $plan = $this->planner(new FakeClassifierAgent)->plan($manifest);

        $subsumedTargets = array_map(
            static fn (DecisionEntry $e): string => $e->target,
            array_values(array_filter(
                $plan->ledger->entries->items(),
                static fn (DecisionEntry $e) => $e->action === DecisionAction::Subsumed,
            )),
        );
        $this->assertNotEmpty($subsumedTargets, 'precondition: TEAMS subtree should produce Subsumed entries');

        $result = $this->renderer()->run($plan, $manifest);

        $renderedSlugs = array_map(
            static fn (PuckOutput $p): string => $p->page_slug,
            $result->pages->items(),
        );

        // None of the subsumed targets' slugs leak into rendered pages.
        // (We don't compare exact slugs because a subsumed page's slug
        // would come from PageSlug::of(), but the renderer never sees
        // those entries, so the more useful assertion is the count: only
        // the 2 PlatformDynamic-rooted pages render.)
        $this->assertCount(2, $renderedSlugs);
    }

    #[Test]
    public function tbirdhoops_offline_sitemap_produces_zero_platform_pages_no_phantom_failures(): void
    {
        // tbirdhoops's offline rootNav fixtures carry Home, About Us +
        // children (Our Board, Our Facilities, Contacts), TBird News,
        // Parents + SportsEngine child, and Unsubscribe. NONE of these
        // match the deterministic name-map (Contacts is intentionally
        // excluded from the map per RootNavPlanner; "TBird News" is a
        // Page kind, not NewsNode). With FakeClassifierAgent returning
        // Keep@0.85 for ambiguous pages, no LLM-driven platform_dynamic
        // emerges either.
        //
        // Correct answer for the OFFLINE FIXTURE: zero PlatformDynamic
        // ledger entries → zero platform PuckOutputs → zero failures →
        // status Complete. The renderer does NOT phantom-render or
        // phantom-fail pages that aren't platform_dynamic.
        //
        // SCOPE NOTE: this is a renderer-correctness signal (no
        // phantom-render). It is NOT a claim that the live tbirdhoops
        // site has no platform content under production PLAN — real
        // Haiku might LLM-classify pages this offline fake keeps.
        $manifest = RealManifests::tbirdhoops();
        $plan = $this->planner(new FakeClassifierAgent)->plan($manifest);

        $platformEntryCount = count(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => $e->action === DecisionAction::PlatformDynamic,
        ));
        $this->assertSame(
            0,
            $platformEntryCount,
            'tbirdhoops offline fixtures should produce zero platform_dynamic entries; '
            .'if a future PLAN heuristic finds one here, update this test',
        );

        $result = $this->renderer()->run($plan, $manifest);

        $this->assertSame(PlatformRenderStatus::Complete, $result->status);
        $this->assertCount(0, $result->pages);
        $this->assertCount(0, $result->failures);
    }

    #[Test]
    public function langdondiamonds_calendar_renders_as_platform_calendar(): void
    {
        // langdondiamonds has a Calendar node_type sibling, routed
        // deterministically to PlatformBlockType::Calendar in PLAN. Cheap
        // cross-fixture confirmation that Calendar rendering isn't
        // tenacity-specific.
        $manifest = RealManifests::langdondiamonds();
        $plan = $this->planner(new FakeClassifierAgent)->plan($manifest);

        // Find the Calendar PlatformDynamic entry — drives the assertion
        // even if PLAN adds more PlatformDynamic entries in this fixture
        // later.
        $calendarEntry = null;
        foreach ($plan->ledger->entries->items() as $entry) {
            if ($entry->action === DecisionAction::PlatformDynamic
                && $entry->platform_block_type === PlatformBlockType::Calendar) {
                $calendarEntry = $entry;
                break;
            }
        }
        $this->assertNotNull($calendarEntry, 'langdondiamonds should have a Calendar platform_dynamic entry');

        $result = $this->renderer()->run($plan, $manifest);

        $this->assertSame(PlatformRenderStatus::Complete, $result->status);
        $this->assertGreaterThanOrEqual(1, $result->pages->count());

        $calendarPages = array_values(array_filter(
            $result->pages->items(),
            static fn (PuckOutput $p): bool => ($p->content[0]['type'] ?? null) === 'PlatformCalendar',
        ));
        $this->assertCount(1, $calendarPages);
        $calendarPuck = $calendarPages[0];
        $this->assertSame(['org_id' => $manifest->org_id], $calendarPuck->content[0]['props']);
    }
}
