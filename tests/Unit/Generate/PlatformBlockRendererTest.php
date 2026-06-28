<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\Brand;
use App\Data\ContentRef;
use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\DecisionLedger;
use App\Data\InventoryPage;
use App\Data\Manifest;
use App\Data\NavItem;
use App\Data\NavNode;
use App\Data\PlatformBlockType;
use App\Data\PlatformRenderFailure;
use App\Data\PlatformRenderResult;
use App\Data\PlatformRenderStatus;
use App\Data\PuckOutput;
use App\Data\SitePlan;
use App\Data\SiteStructure;
use App\Services\Generate\PlatformBlockRenderer;
use App\Services\Schema\ComponentSchema;
use App\Services\Schema\DefaultPuckComponentSchema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// PlatformBlockRenderer reconciliation tie-out + per-enum mapping.
//
// Validates the N-in-N-out posture (every PlatformDynamic ledger entry
// → PuckOutput OR PlatformRenderFailure exactly once), the deterministic
// PlatformBlockType → Puck `type` table (one assertion per enum value
// so adding a 10th type without a schema entry goes red), and the
// three defensive failure modes.
final class PlatformBlockRendererTest extends TestCase
{
    private function renderer(?ComponentSchema $schema = null): PlatformBlockRenderer
    {
        return new PlatformBlockRenderer($schema ?? new DefaultPuckComponentSchema);
    }

    private function manifest(string $orgId = 'ngin-12345'): Manifest
    {
        return new Manifest(
            source_url: 'https://example.org/',
            org_id: $orgId,
            structure: new SiteStructure(
                nav: new DataCollection(NavNode::class, []),
                pages_total: 0,
            ),
            provisioning: null,
            brand: new Brand(logo_source: 'header'),
            content_refs: new DataCollection(ContentRef::class, []),
            asset_refs: new DataCollection(\App\Data\AssetRef::class, []),
            confidence: 1.0,
        );
    }

    private function page(string $label, ?string $url = null, ?int $nodeId = null, string $kind = 'page'): InventoryPage
    {
        return new InventoryPage(
            label: $label,
            url: $url,
            kind: $kind,
            node_type: 'Page',
            page_node_id: $nodeId,
            external_subtype: null,
            depth: 1,
            nav_path: [],
            has_children: false,
        );
    }

    /**
     * @param  array<int, InventoryPage>  $keptPages
     * @param  array<int, DecisionEntry>  $entries
     */
    private function plan(array $keptPages, array $entries, ?NavItem $navStub = null): SitePlan
    {
        return new SitePlan(
            nav: new DataCollection(NavItem::class, $navStub !== null ? [$navStub] : []),
            kept_pages: new DataCollection(InventoryPage::class, $keptPages),
            ledger: new DecisionLedger(
                entries: new DataCollection(DecisionEntry::class, $entries),
            ),
        );
    }

    private function targetFor(InventoryPage $page): string
    {
        if ($page->url !== null && $page->url !== '') {
            return $page->url;
        }
        if ($page->page_node_id !== null) {
            return 'page_node:'.$page->page_node_id;
        }

        return 'label:'.\Illuminate\Support\Str::slug($page->label);
    }

    /**
     * @return iterable<string, array{0: PlatformBlockType, 1: string}>
     */
    public static function platformTypeProvider(): iterable
    {
        yield 'schedule' => [PlatformBlockType::Schedule, 'PlatformSchedule'];
        yield 'scores' => [PlatformBlockType::Scores, 'PlatformScores'];
        yield 'standings' => [PlatformBlockType::Standings, 'PlatformStandings'];
        yield 'roster' => [PlatformBlockType::Roster, 'PlatformRoster'];
        yield 'teams' => [PlatformBlockType::Teams, 'PlatformTeams'];
        yield 'divisions' => [PlatformBlockType::Divisions, 'PlatformDivisions'];
        yield 'contacts' => [PlatformBlockType::Contacts, 'PlatformContacts'];
        yield 'calendar' => [PlatformBlockType::Calendar, 'PlatformCalendar'];
        yield 'news' => [PlatformBlockType::News, 'PlatformNews'];
    }

    /**
     * Drift guard: every PlatformBlockType enum value must have a matching
     * platformBlocks() definition in DefaultPuckComponentSchema. Adding
     * a 10th type without updating the schema will go red here AND in the
     * provider-driven `renders_each_platform_block_type` test below.
     */
    #[Test]
    public function every_platform_block_type_enum_has_a_schema_definition(): void
    {
        $schema = new DefaultPuckComponentSchema;
        $defs = $schema->platformBlocks();

        foreach (PlatformBlockType::cases() as $case) {
            $puckType = match ($case) {
                PlatformBlockType::Schedule => 'PlatformSchedule',
                PlatformBlockType::Scores => 'PlatformScores',
                PlatformBlockType::Standings => 'PlatformStandings',
                PlatformBlockType::Roster => 'PlatformRoster',
                PlatformBlockType::Teams => 'PlatformTeams',
                PlatformBlockType::Divisions => 'PlatformDivisions',
                PlatformBlockType::Contacts => 'PlatformContacts',
                PlatformBlockType::Calendar => 'PlatformCalendar',
                PlatformBlockType::News => 'PlatformNews',
            };
            $this->assertArrayHasKey(
                $puckType,
                $defs,
                "PlatformBlockType::{$case->name} must have a matching '{$puckType}' definition in platformBlocks()",
            );
        }
    }

    /**
     * One PuckOutput per enum value, asserting both the Puck `type`
     * and the single {org_id} prop. Catches enum/schema drift AND any
     * change to the v1 prop contract.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('platformTypeProvider')]
    #[Test]
    public function renders_each_platform_block_type(PlatformBlockType $type, string $expectedPuckType): void
    {
        $page = $this->page('Some Page', '/page', 100);
        $entry = new DecisionEntry(
            target: $this->targetFor($page),
            action: DecisionAction::PlatformDynamic,
            reason: "rebuilt by TeamLinkt {$type->value} block",
            confidence: 1.0,
            platform_block_type: $type,
        );
        $plan = $this->plan([$page], [$entry]);
        $manifest = $this->manifest('ngin-42');

        $result = $this->renderer()->run($plan, $manifest);

        $this->assertSame(PlatformRenderStatus::Complete, $result->status);
        $this->assertCount(1, $result->pages);
        $this->assertCount(0, $result->failures);

        /** @var PuckOutput $puck */
        $puck = $result->pages->items()[0];
        $this->assertSame('page-100', $puck->page_slug);
        $this->assertSame('Some Page', $puck->root['title']);
        $this->assertCount(1, $puck->content);
        $this->assertSame($expectedPuckType, $puck->content[0]['type']);
        $this->assertSame(['org_id' => 'ngin-42'], $puck->content[0]['props']);
        $this->assertSame([], $puck->zones);
    }

    #[Test]
    public function empty_ledger_returns_complete_with_no_pages_no_failures(): void
    {
        $plan = $this->plan([], []);
        $result = $this->renderer()->run($plan, $this->manifest());

        $this->assertSame(PlatformRenderStatus::Complete, $result->status);
        $this->assertCount(0, $result->pages);
        $this->assertCount(0, $result->failures);
    }

    #[Test]
    public function ledger_with_only_keep_or_park_entries_yields_no_platform_pages(): void
    {
        $contentPage = $this->page('About', '/about', 1);
        $parkedPage = $this->page('Stub', '/stub', 2);
        $plan = $this->plan(
            [$contentPage, $parkedPage],
            [
                new DecisionEntry(target: '/about', action: DecisionAction::Keep, reason: '', confidence: 0.9),
                new DecisionEntry(target: '/stub', action: DecisionAction::Park, reason: '', confidence: 0.95),
            ],
        );

        $result = $this->renderer()->run($plan, $this->manifest());

        $this->assertSame(PlatformRenderStatus::Complete, $result->status);
        $this->assertCount(0, $result->pages);
        $this->assertCount(0, $result->failures);
    }

    #[Test]
    public function reconciliation_every_platform_dynamic_entry_lands_in_pages_or_failures_exactly_once(): void
    {
        // Three PlatformDynamic entries. One renders cleanly. Two fail
        // (one for failure mode 1 — target not in kept_pages; one for
        // failure mode 3 — drifted schema). Expected: 1 PuckOutput +
        // 2 PlatformRenderFailures with disjoint page_slugs.
        $okPage = $this->page('Schedule', '/schedule', 10);
        $driftPage = $this->page('News', '/news', 30);

        $entries = [
            new DecisionEntry(
                target: '/schedule',
                action: DecisionAction::PlatformDynamic,
                reason: '',
                confidence: 1.0,
                platform_block_type: PlatformBlockType::Schedule,
            ),
            // Failure mode 1: ledger target with no kept_pages entry.
            new DecisionEntry(
                target: '/missing',
                action: DecisionAction::PlatformDynamic,
                reason: '',
                confidence: 1.0,
                platform_block_type: PlatformBlockType::Roster,
            ),
            // Failure mode 3: schema drift — use a stub schema that
            // omits PlatformNews.
            new DecisionEntry(
                target: '/news',
                action: DecisionAction::PlatformDynamic,
                reason: '',
                confidence: 1.0,
                platform_block_type: PlatformBlockType::News,
            ),
        ];

        // kept_pages omits the /missing entry (failure mode 1).
        $plan = $this->plan([$okPage, $driftPage], $entries);

        $schema = new class implements ComponentSchema
        {
            private DefaultPuckComponentSchema $base;

            public function __construct()
            {
                $this->base = new DefaultPuckComponentSchema;
            }

            public function all(): array
            {
                return $this->base->all();
            }

            public function get(string $componentType): ?\App\Data\ComponentDefinition
            {
                return $this->base->get($componentType);
            }

            public function types(): array
            {
                return $this->base->types();
            }

            public function platformBlocks(): array
            {
                $defs = $this->base->platformBlocks();
                unset($defs['PlatformNews']);

                return $defs;
            }
        };

        $result = $this->renderer($schema)->run($plan, $this->manifest());

        $this->assertSame(PlatformRenderStatus::Partial, $result->status);
        $this->assertCount(1, $result->pages);
        $this->assertCount(2, $result->failures);

        $pageSlugs = array_map(static fn (PuckOutput $p): string => $p->page_slug, $result->pages->items());
        $failureSlugs = array_map(static fn (PlatformRenderFailure $f): string => $f->page_slug, $result->failures->items());
        $all = array_merge($pageSlugs, $failureSlugs);
        sort($all);

        $this->assertSame(['missing', 'page-10', 'page-30'], array_values(array_unique($all)));
        $this->assertSame([], array_intersect($pageSlugs, $failureSlugs));
    }

    /**
     * Failure mode 1: ledger target doesn't match any kept_pages entry.
     * Defensive — unreachable under current RootNavPlanner invariants,
     * but if PLAN changes to drop PlatformDynamic from kept_pages, the
     * renderer must surface a visible failure instead of silently
     * skipping.
     */
    #[Test]
    public function failure_target_not_in_kept_pages(): void
    {
        $entry = new DecisionEntry(
            target: '/orphan',
            action: DecisionAction::PlatformDynamic,
            reason: '',
            confidence: 1.0,
            platform_block_type: PlatformBlockType::Schedule,
        );
        $plan = $this->plan([], [$entry]);

        $result = $this->renderer()->run($plan, $this->manifest());

        $this->assertSame(PlatformRenderStatus::Partial, $result->status);
        $this->assertCount(0, $result->pages);
        $this->assertCount(1, $result->failures);
        /** @var PlatformRenderFailure $fail */
        $fail = $result->failures->items()[0];
        $this->assertStringContainsString("no kept_pages entry matches ledger target '/orphan'", $fail->reason);
    }

    /**
     * Failure mode 2: PlatformDynamic action with a null
     * platform_block_type. Defensive — the planner never emits this,
     * but if a future planner does (or a hand-built SitePlan in a test
     * does), surface it loudly.
     */
    #[Test]
    public function failure_platform_block_type_null(): void
    {
        $page = $this->page('Mystery', '/mystery', 7);
        $entry = new DecisionEntry(
            target: '/mystery',
            action: DecisionAction::PlatformDynamic,
            reason: '',
            confidence: 1.0,
            platform_block_type: null,
        );
        $plan = $this->plan([$page], [$entry]);

        $result = $this->renderer()->run($plan, $this->manifest());

        $this->assertSame(PlatformRenderStatus::Partial, $result->status);
        $this->assertCount(0, $result->pages);
        $this->assertCount(1, $result->failures);
        /** @var PlatformRenderFailure $fail */
        $fail = $result->failures->items()[0];
        $this->assertSame('page-7', $fail->page_slug);
        $this->assertSame('Mystery', $fail->page_title);
        $this->assertSame(7, $fail->page_node_id);
        $this->assertSame('ledger entry action=platform_dynamic but platform_block_type is null', $fail->reason);
    }

    /**
     * Failure mode 3: enum value exists but ComponentSchema.platformBlocks()
     * has no matching definition. Catches enum/schema drift.
     */
    #[Test]
    public function failure_schema_drift_no_definition(): void
    {
        $page = $this->page('News', '/news', 9);
        $entry = new DecisionEntry(
            target: '/news',
            action: DecisionAction::PlatformDynamic,
            reason: '',
            confidence: 1.0,
            platform_block_type: PlatformBlockType::News,
        );
        $plan = $this->plan([$page], [$entry]);

        $schema = new class implements ComponentSchema
        {
            private DefaultPuckComponentSchema $base;

            public function __construct()
            {
                $this->base = new DefaultPuckComponentSchema;
            }

            public function all(): array
            {
                return $this->base->all();
            }

            public function get(string $componentType): ?\App\Data\ComponentDefinition
            {
                return $this->base->get($componentType);
            }

            public function types(): array
            {
                return $this->base->types();
            }

            public function platformBlocks(): array
            {
                return [];
            }
        };

        $result = $this->renderer($schema)->run($plan, $this->manifest());

        $this->assertSame(PlatformRenderStatus::Partial, $result->status);
        $this->assertCount(0, $result->pages);
        $this->assertCount(1, $result->failures);
        /** @var PlatformRenderFailure $fail */
        $fail = $result->failures->items()[0];
        $this->assertSame('page-9', $fail->page_slug);
        $this->assertSame("no platformBlocks() definition for type 'news'", $fail->reason);
    }

    #[Test]
    public function slug_uses_page_node_id_when_present_otherwise_label_slug(): void
    {
        // page_node_id wins.
        $pageWithId = $this->page('Some Roster', '/r', 555);
        // page_node_id absent → falls back to Str::slug(label) via PageSlug.
        $pageNoId = new InventoryPage(
            label: 'Adhoc',
            url: '/adhoc',
            kind: 'page',
            node_type: null,
            page_node_id: null,
            external_subtype: null,
            depth: 1,
            nav_path: [],
            has_children: false,
        );

        $entries = [
            new DecisionEntry(
                target: '/r',
                action: DecisionAction::PlatformDynamic,
                reason: '',
                confidence: 1.0,
                platform_block_type: PlatformBlockType::Roster,
            ),
            new DecisionEntry(
                target: '/adhoc',
                action: DecisionAction::PlatformDynamic,
                reason: '',
                confidence: 1.0,
                platform_block_type: PlatformBlockType::Calendar,
            ),
        ];

        $plan = $this->plan([$pageWithId, $pageNoId], $entries);
        $result = $this->renderer()->run($plan, $this->manifest());

        $this->assertSame(PlatformRenderStatus::Complete, $result->status);
        $this->assertCount(2, $result->pages);
        $slugs = array_map(static fn (PuckOutput $p): string => $p->page_slug, $result->pages->items());
        sort($slugs);
        $this->assertSame(['adhoc', 'page-555'], $slugs);
    }

    #[Test]
    public function org_id_threads_from_manifest_into_block_props(): void
    {
        $page = $this->page('Schedule', '/schedule', 1);
        $entry = new DecisionEntry(
            target: '/schedule',
            action: DecisionAction::PlatformDynamic,
            reason: '',
            confidence: 1.0,
            platform_block_type: PlatformBlockType::Schedule,
        );
        $plan = $this->plan([$page], [$entry]);

        $resultA = $this->renderer()->run($plan, $this->manifest('ngin-A'));
        $resultB = $this->renderer()->run($plan, $this->manifest('ngin-B'));

        /** @var PuckOutput $puckA */
        $puckA = $resultA->pages->items()[0];
        /** @var PuckOutput $puckB */
        $puckB = $resultB->pages->items()[0];

        $this->assertSame('ngin-A', $puckA->content[0]['props']['org_id']);
        $this->assertSame('ngin-B', $puckB->content[0]['props']['org_id']);
    }

    #[Test]
    public function target_join_by_page_node_id_when_url_is_null(): void
    {
        // SE 'toolsLink' style — null URL, page_node_id-derived target.
        $page = new InventoryPage(
            label: 'Dibs',
            url: null,
            kind: 'page',
            node_type: 'Page',
            page_node_id: 999,
            external_subtype: null,
            depth: 1,
            nav_path: [],
            has_children: false,
        );
        $entry = new DecisionEntry(
            target: 'page_node:999',
            action: DecisionAction::PlatformDynamic,
            reason: '',
            confidence: 1.0,
            platform_block_type: PlatformBlockType::Teams,
        );
        $plan = $this->plan([$page], [$entry]);

        $result = $this->renderer()->run($plan, $this->manifest());

        $this->assertSame(PlatformRenderStatus::Complete, $result->status);
        $this->assertCount(1, $result->pages);
        /** @var PuckOutput $puck */
        $puck = $result->pages->items()[0];
        $this->assertSame('page-999', $puck->page_slug);
        $this->assertSame('PlatformTeams', $puck->content[0]['type']);
    }

    #[Test]
    public function result_is_a_platform_render_result(): void
    {
        // Sanity check: the public contract returns the documented DTO.
        $result = $this->renderer()->run($this->plan([], []), $this->manifest());
        $this->assertInstanceOf(PlatformRenderResult::class, $result);
    }
}
