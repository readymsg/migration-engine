<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\AssemblyBlockIssue;
use App\Data\AssemblyCoercion;
use App\Data\AssemblyFailure;
use App\Data\AssemblyResult;
use App\Data\AssemblyStatus;
use App\Data\ConversionFailure;
use App\Data\ConversionStage;
use App\Data\ConversionStatus;
use App\Data\DecisionEntry;
use App\Data\DecisionLedger;
use App\Data\GlobalStyleBrief;
use App\Data\InventoryPage;
use App\Data\Manifest;
use App\Data\NavItem;
use App\Data\PlatformRenderFailure;
use App\Data\PlatformRenderResult;
use App\Data\PlatformRenderStatus;
use App\Data\PuckOutput;
use App\Data\ResolvedNavItem;
use App\Data\ResolvedNavStatus;
use App\Data\SitePlan;
use App\Services\Generate\DraftLanding;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Generate\RecordingProductClient;
use Tests\TestCase;

// Slice 2f — DraftLanding unit tests. Synthetic SitePlan +
// AssemblyResult + PlatformRenderResult to exercise the status truth
// table, the Failed-skips-createDraftSite invariant, the defensive
// slug-collision guard, and the nav reconciliation contract — without
// touching the real tbirdhoops fixture (that lives in its own replay
// test).
final class DraftLandingTest extends TestCase
{
    #[Test]
    public function completed_status_lands_draft_and_resolves_nav_to_page_ids(): void
    {
        $home = $this->page('Home', 'https://example.org/home', 100);
        $about = $this->page('About', 'https://example.org/about', 101);
        $plan = $this->plan(
            keptPages: [$home, $about],
            nav: [
                new NavItem(label: 'Home', page_slug: 'home', order: 0),
                new NavItem(label: 'About', page_slug: 'about', order: 1),
            ],
            ledger: [],
        );

        $assembly = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, [
                new PuckOutput(page_slug: 'page-100', content: [['type' => 'Hero', 'props' => []]], root: ['title' => 'Home']),
                new PuckOutput(page_slug: 'page-101', content: [['type' => 'Text', 'props' => ['body' => 'About copy']]], root: ['title' => 'About']),
            ]),
            failures: new DataCollection(AssemblyFailure::class, []),
            block_issues_by_slug: [],
            status: AssemblyStatus::Complete,
            style_brief: $this->emptyBrief(),
        );

        $platform = new PlatformRenderResult(
            pages: new DataCollection(PuckOutput::class, []),
            failures: new DataCollection(PlatformRenderFailure::class, []),
            status: PlatformRenderStatus::Complete,
        );

        $client = new RecordingProductClient;
        $client->returns('DRAFT_1', 'https://teamlinkt.test/drafts/DRAFT_1');
        $lander = new DraftLanding($client);

        $result = $lander->run(
            conversionId: 'conv-xyz',
            plan: $plan,
            assembly: $assembly,
            platform: $platform,
            manifest: $this->minimalManifest('ngin-99'),
        );

        $this->assertSame(ConversionStatus::Completed, $result->status);
        $this->assertSame(['page-100', 'page-101'], array_keys($result->page_map));
        $this->assertSame('DRAFT_1', $result->draft_id);
        $this->assertSame('https://teamlinkt.test/drafts/DRAFT_1', $result->draft_url);

        // Nav reconciliation: NavItem.page_slug was 'home'/'about' (planner's
        // OLD label-derived form, simulating the existing fixture). After
        // reconciliation each nav entry's page_slug joins into page_map.
        /** @var array<int, ResolvedNavItem> $resolvedNav */
        $resolvedNav = $result->nav->items();
        $this->assertCount(2, $resolvedNav);
        $this->assertSame('page-100', $resolvedNav[0]->page_slug);
        $this->assertSame(ResolvedNavStatus::Resolved, $resolvedNav[0]->status);
        $this->assertSame('page-101', $resolvedNav[1]->page_slug);
        $this->assertSame(ResolvedNavStatus::Resolved, $resolvedNav[1]->status);
        foreach ($resolvedNav as $r) {
            $this->assertArrayHasKey($r->page_slug, $result->page_map, "resolved nav slug {$r->page_slug} must key into page_map");
        }

        $this->assertCount(1, $client->calls);
        $this->assertSame('ngin-99', $client->calls[0]['org_id']);
        $this->assertSame(['page-100', 'page-101'], array_keys($client->calls[0]['puck']));
        $this->assertSame([], $client->calls[0]['provisioning']);
    }

    #[Test]
    public function partial_status_when_assembly_is_partial_still_lands(): void
    {
        $home = $this->page('Home', 'https://example.org/home', 100);
        $plan = $this->plan(
            keptPages: [$home],
            nav: [new NavItem(label: 'Home', page_slug: 'home', order: 0)],
            ledger: [],
        );

        $assembly = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, [
                new PuckOutput(page_slug: 'page-100', content: [['type' => 'Hero', 'props' => []]], root: ['title' => 'Home']),
            ]),
            failures: new DataCollection(AssemblyFailure::class, []),
            block_issues_by_slug: [
                'page-100' => [new AssemblyBlockIssue(
                    block_index: 0,
                    component_type: 'ButtonGroup',
                    coercion: AssemblyCoercion::Drop,
                    reason: 'no buttons left',
                )],
            ],
            status: AssemblyStatus::Partial,
            style_brief: $this->emptyBrief(),
        );

        $platform = new PlatformRenderResult(
            pages: new DataCollection(PuckOutput::class, []),
            failures: new DataCollection(PlatformRenderFailure::class, []),
            status: PlatformRenderStatus::Complete,
        );

        $client = new RecordingProductClient;
        $lander = new DraftLanding($client);

        $result = $lander->run('c', $plan, $assembly, $platform, $this->minimalManifest('ngin-99'));

        $this->assertSame(ConversionStatus::Partial, $result->status);
        $this->assertNotNull($result->draft_url, 'Partial conversions MUST still land a draft so the reviewer has something to look at');
        $this->assertCount(1, $client->calls);
        $this->assertArrayHasKey('page-100', $result->block_issues_by_slug, 'block_issues are carried forward into ConversionResult for SCORE & LOG');
    }

    #[Test]
    public function partial_status_when_platform_renderer_is_partial(): void
    {
        $home = $this->page('Home', 'https://example.org/home', 100);
        $plan = $this->plan(
            keptPages: [$home],
            nav: [new NavItem(label: 'Home', page_slug: 'home', order: 0)],
            ledger: [],
        );

        $assembly = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, [
                new PuckOutput(page_slug: 'page-100', content: [['type' => 'Hero', 'props' => []]], root: ['title' => 'Home']),
            ]),
            failures: new DataCollection(AssemblyFailure::class, []),
            block_issues_by_slug: [],
            status: AssemblyStatus::Complete,
            style_brief: $this->emptyBrief(),
        );

        $platform = new PlatformRenderResult(
            pages: new DataCollection(PuckOutput::class, []),
            failures: new DataCollection(PlatformRenderFailure::class, [
                new PlatformRenderFailure(
                    page_slug: 'page-200',
                    page_title: 'Calendar',
                    page_node_id: 200,
                    reason: 'no platformBlocks() definition for type unknown',
                ),
            ]),
            status: PlatformRenderStatus::Partial,
        );

        $client = new RecordingProductClient;
        $lander = new DraftLanding($client);

        $result = $lander->run('c', $plan, $assembly, $platform, $this->minimalManifest('ngin-99'));

        $this->assertSame(ConversionStatus::Partial, $result->status);
        $this->assertCount(1, $result->failures);
        /** @var array<int, ConversionFailure> $fs */
        $fs = $result->failures->items();
        $this->assertSame(ConversionStage::PlatformRender, $fs[0]->stage);
        $this->assertCount(1, $client->calls, 'Partial still calls createDraftSite');
    }

    #[Test]
    public function failed_assembly_skips_create_draft_site(): void
    {
        $plan = $this->plan(keptPages: [], nav: [], ledger: []);

        $assembly = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, []),
            failures: new DataCollection(AssemblyFailure::class, [
                new AssemblyFailure(
                    page_slug: 'page-100',
                    page_title: 'Home',
                    page_node_id: 100,
                    reason: 'block-fill-failure: ir-pass-failure: upstream abort',
                ),
            ]),
            block_issues_by_slug: [],
            status: AssemblyStatus::Failed,
            style_brief: $this->emptyBrief(),
        );

        $platform = new PlatformRenderResult(
            pages: new DataCollection(PuckOutput::class, []),
            failures: new DataCollection(PlatformRenderFailure::class, []),
            status: PlatformRenderStatus::Complete,
        );

        $client = new RecordingProductClient;
        $lander = new DraftLanding($client);

        $result = $lander->run('c', $plan, $assembly, $platform, $this->minimalManifest('ngin-99'));

        $this->assertSame(ConversionStatus::Failed, $result->status);
        $this->assertNull($result->draft_id);
        $this->assertNull($result->draft_url);
        $this->assertSame(0, count($client->calls), 'Failed status MUST NOT call createDraftSite');

        // Upstream failure chain preserved with correct stage attribution.
        /** @var array<int, ConversionFailure> $fs */
        $fs = $result->failures->items();
        $this->assertCount(1, $fs);
        $this->assertSame(ConversionStage::IrPass, $fs[0]->stage, 'block-fill-failure: ir-pass-failure: prefix attributes to IrPass');
    }

    #[Test]
    public function chained_failure_reasons_attribute_to_correct_stages(): void
    {
        $plan = $this->plan(keptPages: [], nav: [], ledger: []);

        $assembly = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, []),
            failures: new DataCollection(AssemblyFailure::class, [
                new AssemblyFailure('p1', 'P1', 1, 'block-fill-failure: page silently absent'),
                new AssemblyFailure('p2', 'P2', 2, 'block-fill-failure: ir-pass-failure: missing slug'),
                new AssemblyFailure('p3', 'P3', 3, 'every block on this page was dropped during coercion (3 issues)'),
            ]),
            block_issues_by_slug: [],
            status: AssemblyStatus::Partial,
            style_brief: $this->emptyBrief(),
        );

        $platform = new PlatformRenderResult(
            pages: new DataCollection(PuckOutput::class, []),
            failures: new DataCollection(PlatformRenderFailure::class, []),
            status: PlatformRenderStatus::Complete,
        );

        $client = new RecordingProductClient;
        $lander = new DraftLanding($client);

        $result = $lander->run('c', $plan, $assembly, $platform, $this->minimalManifest('ngin-99'));

        /** @var array<int, ConversionFailure> $fs */
        $fs = $result->failures->items();
        $stages = array_map(static fn ($f) => $f->stage, $fs);
        $this->assertContains(ConversionStage::BlockFill, $stages);
        $this->assertContains(ConversionStage::IrPass, $stages);
        $this->assertContains(ConversionStage::Assembler, $stages);
    }

    #[Test]
    public function slug_collision_between_streams_is_surfaced_as_draft_landing_failure(): void
    {
        $home = $this->page('Home', 'https://example.org/home', 100);
        $plan = $this->plan(
            keptPages: [$home],
            nav: [new NavItem(label: 'Home', page_slug: 'home', order: 0)],
            ledger: [],
        );

        // Synthetic anomaly: same slug in both streams. Unreachable under
        // current planner invariants (a page is keep XOR platform_dynamic)
        // but the lander defensively flags it instead of silently
        // overwriting one with the other.
        $assembly = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, [
                new PuckOutput(page_slug: 'page-100', content: [['type' => 'Hero', 'props' => []]], root: ['title' => 'Home']),
            ]),
            failures: new DataCollection(AssemblyFailure::class, []),
            block_issues_by_slug: [],
            status: AssemblyStatus::Complete,
            style_brief: $this->emptyBrief(),
        );

        $platform = new PlatformRenderResult(
            pages: new DataCollection(PuckOutput::class, [
                new PuckOutput(page_slug: 'page-100', content: [['type' => 'PlatformCalendar', 'props' => ['org_id' => 'x']]], root: ['title' => 'Home']),
            ]),
            failures: new DataCollection(PlatformRenderFailure::class, []),
            status: PlatformRenderStatus::Complete,
        );

        $client = new RecordingProductClient;
        $lander = new DraftLanding($client);

        $result = $lander->run('c', $plan, $assembly, $platform, $this->minimalManifest('ngin-99'));

        $this->assertSame(ConversionStatus::Partial, $result->status, 'a collision degrades status to at least Partial');
        // Content page wins; platform entry is dropped.
        $this->assertSame('Hero', $result->page_map['page-100']['content'][0]['type']);

        /** @var array<int, ConversionFailure> $fs */
        $fs = $result->failures->items();
        $collision = array_values(array_filter($fs, static fn ($f) => str_contains($f->reason, 'slug collision')));
        $this->assertCount(1, $collision);
        $this->assertSame(ConversionStage::DraftLanding, $collision[0]->stage);
    }

    #[Test]
    public function client_error_during_create_draft_site_is_surfaced_as_failure(): void
    {
        $home = $this->page('Home', 'https://example.org/home', 100);
        $plan = $this->plan(
            keptPages: [$home],
            nav: [new NavItem(label: 'Home', page_slug: 'home', order: 0)],
            ledger: [],
        );

        $assembly = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, [
                new PuckOutput(page_slug: 'page-100', content: [['type' => 'Hero', 'props' => []]], root: ['title' => 'Home']),
            ]),
            failures: new DataCollection(AssemblyFailure::class, []),
            block_issues_by_slug: [],
            status: AssemblyStatus::Complete,
            style_brief: $this->emptyBrief(),
        );

        $platform = new PlatformRenderResult(
            pages: new DataCollection(PuckOutput::class, []),
            failures: new DataCollection(PlatformRenderFailure::class, []),
            status: PlatformRenderStatus::Complete,
        );

        $client = new RecordingProductClient;
        $client->throwOnNextCall(new RuntimeException('product API 503'));
        $lander = new DraftLanding($client);

        $result = $lander->run('c', $plan, $assembly, $platform, $this->minimalManifest('ngin-99'));

        $this->assertSame(ConversionStatus::Partial, $result->status, 'client error degrades a Completed conversion to Partial');
        $this->assertNull($result->draft_id);
        $this->assertNull($result->draft_url);

        /** @var array<int, ConversionFailure> $fs */
        $fs = $result->failures->items();
        $clientErr = array_values(array_filter($fs, static fn ($f) => str_contains($f->reason, '503')));
        $this->assertCount(1, $clientErr);
        $this->assertSame(ConversionStage::DraftLanding, $clientErr[0]->stage);
    }

    #[Test]
    public function external_nav_items_are_marked_unmatched_external_not_failed(): void
    {
        // A LinkNode external sibling: kept (kind=external), in nav, but
        // produces no PuckOutput. Reconciliation marks it
        // UnmatchedExternal — NOT a draft-landing failure (nav-layer
        // concern for a later slice that wires external URLs into the
        // rebuilt nav).
        $home = $this->page('Home', 'https://example.org/home', 100);
        $shop = new InventoryPage(
            label: 'Shop',
            url: 'https://shop.example.org',
            kind: 'external',
            node_type: 'LinkNode',
            page_node_id: 999,
            external_subtype: 'external_link',
            depth: 0,
            nav_path: [],
        );

        $plan = $this->plan(
            keptPages: [$home, $shop],
            nav: [
                new NavItem(label: 'Home', page_slug: 'home', order: 0),
                new NavItem(label: 'Shop', page_slug: 'shop', order: 1),
            ],
            ledger: [],
        );

        $assembly = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, [
                new PuckOutput(page_slug: 'page-100', content: [['type' => 'Hero', 'props' => []]], root: ['title' => 'Home']),
            ]),
            failures: new DataCollection(AssemblyFailure::class, []),
            block_issues_by_slug: [],
            status: AssemblyStatus::Complete,
            style_brief: $this->emptyBrief(),
        );

        $platform = new PlatformRenderResult(
            pages: new DataCollection(PuckOutput::class, []),
            failures: new DataCollection(PlatformRenderFailure::class, []),
            status: PlatformRenderStatus::Complete,
        );

        $client = new RecordingProductClient;
        $lander = new DraftLanding($client);

        $result = $lander->run('c', $plan, $assembly, $platform, $this->minimalManifest('ngin-99'));

        /** @var array<int, ResolvedNavItem> $nav */
        $nav = $result->nav->items();
        $this->assertCount(2, $nav);
        $this->assertSame(ResolvedNavStatus::Resolved, $nav[0]->status, 'Home resolves cleanly');
        $this->assertSame(ResolvedNavStatus::UnmatchedExternal, $nav[1]->status, 'external Shop marked unmatched_external');

        $this->assertSame(ConversionStatus::Completed, $result->status, 'external nav is not a failure');
        $this->assertSame(0, $result->failures->count());
    }

    /**
     * @param  array<int, InventoryPage>  $keptPages
     * @param  array<int, NavItem>  $nav
     * @param  array<int, DecisionEntry>  $ledger
     */
    private function plan(array $keptPages, array $nav, array $ledger): SitePlan
    {
        return new SitePlan(
            nav: new DataCollection(NavItem::class, $nav),
            kept_pages: new DataCollection(InventoryPage::class, $keptPages),
            ledger: new DecisionLedger(
                entries: new DataCollection(DecisionEntry::class, $ledger),
            ),
        );
    }

    private function page(string $label, ?string $url, int $pageNodeId, int $depth = 0): InventoryPage
    {
        return new InventoryPage(
            label: $label,
            url: $url,
            kind: 'page',
            node_type: 'Page',
            page_node_id: $pageNodeId,
            external_subtype: null,
            depth: $depth,
            nav_path: [],
        );
    }

    private function emptyBrief(): GlobalStyleBrief
    {
        // Style-brief passthrough on AssemblyResult; the lander forwards
        // it onto ConversionResult but doesn't read it. Synthetic tests
        // get an empty brief so they don't have to invent a palette.
        return new GlobalStyleBrief(
            brand_voice: '',
            palette: [],
            layout_conventions: [],
            nav: new DataCollection(NavItem::class, []),
        );
    }

    private function minimalManifest(string $orgId): Manifest
    {
        // The lander only reads ->org_id off the Manifest; constructing the
        // full real-Manifest is heavy. Use the tbirdhoops fake-Manifest from
        // RealManifests when a fixture is needed, but synthetic tests only
        // need org_id and we shouldn't load HTML/rootNav for that.
        return Manifest::from([
            'source_url' => 'https://example.org',
            'org_id' => $orgId,
            'structure' => [
                'nav' => [],
                'pages_total' => 0,
            ],
            'provisioning' => null,
            'brand' => [
                'logo_source' => 'flag',
                'logo_asset_ref' => null,
                'palette' => [],
                'voice_hint' => null,
            ],
            'content_refs' => [],
            'asset_refs' => [],
            'confidence' => 1.0,
            'flags' => [],
            'content_failures' => [],
            'cdn_assets_found' => 0,
            'cdn_assets_rehosted' => 0,
        ]);
    }
}
