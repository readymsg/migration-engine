<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\AssetRef;
use App\Data\Brand;
use App\Data\ConversionFailure;
use App\Data\ConversionResult;
use App\Data\ConversionStatus;
use App\Data\GlobalStyleBrief;
use App\Data\NavItem;
use App\Data\ResolvedNavItem;
use App\Data\ResolvedNavStatus;
use App\Data\SiteImport\Page;
use App\Services\ContractEmitter\PageTreeBuilder;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Pins the page-tree builder against the four slug rules in
// Contract Part II "Slug rules" and the homepage-picking heuristic.
// Content on each Page is empty at this stage — Slice 9 fills it.
final class PageTreeBuilderTest extends TestCase
{
    private PageTreeBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new PageTreeBuilder;
    }

    // ─── homepage detection ─────────────────────────────────────────────

    #[Test]
    public function first_resolved_nav_becomes_homepage_with_empty_slug(): void
    {
        $r = $this->makeResult(
            nav: [
                $this->navResolved('Home', 'page-1', 0),
                $this->navResolved('About', 'page-2', 1),
            ],
            pageMap: ['page-1' => [], 'page-2' => []],
        );

        $tree = $this->builder->build($r);
        $this->assertCount(2, $tree->pages);
        $home = $this->findPageById($tree->pages, 'home');
        $this->assertNotNull($home);
        $this->assertSame('', $home->slug);
        $this->assertSame('Home', $home->title);

        $about = $this->findPageBySlug($tree->pages, 'page-2');
        $this->assertNotNull($about);
        $this->assertSame('About', $about->title);
        $this->assertSame('page-2', $about->id);
    }

    #[Test]
    public function exactly_one_page_has_empty_slug(): void
    {
        // The contract's hardest invariant. Test it independent of
        // homepage-picking so any regression surfaces cleanly.
        $r = $this->makeResult(
            nav: [
                $this->navResolved('Home', 'page-1', 0),
                $this->navResolved('About', 'page-2', 1),
                $this->navResolved('News', 'page-3', 2),
            ],
            pageMap: ['page-1' => [], 'page-2' => [], 'page-3' => []],
        );
        $tree = $this->builder->build($r);
        $emptySlugs = array_filter($tree->pages, fn ($p) => $p->slug === '');
        $this->assertCount(1, $emptySlugs);
    }

    #[Test]
    public function falls_back_to_first_page_map_key_when_nav_has_no_resolved(): void
    {
        $r = $this->makeResult(
            nav: [],
            pageMap: ['page-88' => [], 'page-99' => []],
        );
        $tree = $this->builder->build($r);
        $this->assertCount(2, $tree->pages);
        $home = array_values(array_filter($tree->pages, fn ($p) => $p->slug === ''))[0];
        $this->assertSame('home', $home->id);
        // Diagnostic emitted so a reviewer sees we fell back.
        $codes = array_map(fn ($d) => $d->code, $tree->diagnostics);
        $this->assertContains('homepage_picked_by_fallback', $codes);
    }

    #[Test]
    public function empty_page_map_emits_error_diagnostic(): void
    {
        $r = $this->makeResult(nav: [], pageMap: []);
        $tree = $this->builder->build($r);
        $this->assertCount(0, $tree->pages);
        $codes = array_map(fn ($d) => $d->code, $tree->diagnostics);
        $this->assertContains('no_pages_to_map', $codes);
    }

    // ─── slug rule 2: CI-unique ─────────────────────────────────────────

    #[Test]
    public function ci_collision_disambiguates_with_suffix(): void
    {
        // Two pages whose slugs differ only by case must not both
        // survive — MySQL's utf8mb4_unicode_ci collates them equal.
        $r = $this->makeResult(
            nav: [
                $this->navResolved('Home', 'page-1', 0),
                $this->navResolved('About', 'About', 1),
                $this->navResolved('about', 'about', 2),
            ],
            pageMap: ['page-1' => [], 'About' => [], 'about' => []],
        );
        $tree = $this->builder->build($r);
        $slugs = array_map(fn ($p) => strtolower($p->slug), $tree->pages);
        // No two lowercased slugs may collide.
        $this->assertCount(count(array_unique($slugs)), $slugs);
        $codes = array_map(fn ($d) => $d->code, $tree->diagnostics);
        $this->assertContains('slug_collision_disambiguated', $codes);
    }

    // ─── slug rule 3: `view` reserved ───────────────────────────────────

    #[Test]
    public function view_prefixed_slug_is_renamed(): void
    {
        $r = $this->makeResult(
            nav: [
                $this->navResolved('Home', 'page-1', 0),
                $this->navResolved('View', 'view', 1),
                $this->navResolved('View Something', 'view/x', 2),
            ],
            pageMap: ['page-1' => [], 'view' => [], 'view/x' => []],
        );
        $tree = $this->builder->build($r);
        $slugs = array_map(fn ($p) => $p->slug, $tree->pages);
        // No slug in the output is exactly `view` or starts with `view/`.
        foreach ($slugs as $slug) {
            $this->assertFalse($slug === 'view' || str_starts_with($slug, 'view/'), "`{$slug}` must not survive as-is");
        }
        // Diagnostic emitted for each rename.
        $codes = array_map(fn ($d) => $d->code, $tree->diagnostics);
        $this->assertContains('reserved_slug_renamed', $codes);
    }

    // ─── slug rule 4: lowercase + hyphen + no extensions ────────────────

    #[Test]
    public function slug_lowercased_and_extensions_stripped(): void
    {
        $r = $this->makeResult(
            nav: [
                $this->navResolved('Home', 'page-1', 0),
                $this->navResolved('About HTML', 'About-Us.html', 1),
                $this->navResolved('Programs PHP', 'Programs.php', 2),
            ],
            pageMap: ['page-1' => [], 'About-Us.html' => [], 'Programs.php' => []],
        );
        $tree = $this->builder->build($r);
        $slugs = array_map(fn ($p) => $p->slug, $tree->pages);
        foreach ($slugs as $slug) {
            $this->assertStringNotContainsString('.html', $slug);
            $this->assertStringNotContainsString('.php', $slug);
            $this->assertSame(strtolower($slug), $slug, "slug `{$slug}` must be lowercase");
        }
        $this->assertContains('about-us', $slugs);
        $this->assertContains('programs', $slugs);
    }

    // ─── nav ordering + showInNav ───────────────────────────────────────

    #[Test]
    public function pages_are_ordered_by_nav_order(): void
    {
        $r = $this->makeResult(
            nav: [
                $this->navResolved('Home', 'page-1', 0),
                $this->navResolved('Last', 'page-2', 10),
                $this->navResolved('Middle', 'page-3', 5),
            ],
            pageMap: ['page-1' => [], 'page-2' => [], 'page-3' => []],
        );
        $tree = $this->builder->build($r);
        $navOrders = array_map(fn ($p) => $p->navOrder, $tree->pages);
        $this->assertSame([0, 5, 10], $navOrders);
    }

    #[Test]
    public function unresolved_nav_pages_still_land_but_show_in_nav_is_false(): void
    {
        $r = $this->makeResult(
            nav: [
                $this->navResolved('Home', 'page-1', 0),
                new ResolvedNavItem('Shop', 'page-2', 1, ResolvedNavStatus::UnmatchedExternal, 'link-only'),
            ],
            pageMap: ['page-1' => [], 'page-2' => []], // page-2 exists as content
        );
        $tree = $this->builder->build($r);
        $this->assertCount(2, $tree->pages);
        $shop = $this->findPageById($tree->pages, 'page-2');
        $this->assertNotNull($shop);
        $this->assertFalse($shop->showInNav);
    }

    #[Test]
    public function pages_in_page_map_but_not_in_nav_are_appended_with_show_in_nav_false(): void
    {
        $r = $this->makeResult(
            nav: [
                $this->navResolved('Home', 'page-1', 0),
            ],
            pageMap: [
                'page-1' => [],
                'page-orphan' => [], // not in nav
            ],
        );
        $tree = $this->builder->build($r);
        $this->assertCount(2, $tree->pages);
        $orphan = $this->findPageById($tree->pages, 'page-orphan');
        $this->assertNotNull($orphan);
        $this->assertFalse($orphan->showInNav);
    }

    // ─── id + pageIdBySourceSlug map ────────────────────────────────────

    #[Test]
    public function homepage_id_is_home_and_map_is_correct(): void
    {
        $r = $this->makeResult(
            nav: [
                $this->navResolved('Home', 'page-1', 0),
                $this->navResolved('About', 'page-2', 1),
            ],
            pageMap: ['page-1' => [], 'page-2' => []],
        );
        $tree = $this->builder->build($r);
        $this->assertSame('home', $tree->pageIdBySourceSlug['page-1']);
        $this->assertSame('page-2', $tree->pageIdBySourceSlug['page-2']);
    }

    // ─── content is empty at this stage ─────────────────────────────────

    #[Test]
    public function content_is_empty_on_every_page(): void
    {
        // Slice 9 fills content; the tree builder produces shells.
        $r = $this->makeResult(
            nav: [$this->navResolved('Home', 'page-1', 0)],
            pageMap: ['page-1' => ['content' => [['type' => 'Text', 'props' => ['body' => 'x']]]]],
        );
        $tree = $this->builder->build($r);
        foreach ($tree->pages as $p) {
            $this->assertCount(0, $p->data->content);
        }
    }

    // ─── helpers ────────────────────────────────────────────────────────

    /**
     * @param  array<int, ResolvedNavItem>  $nav
     * @param  array<string, array<string, mixed>>  $pageMap
     */
    private function makeResult(array $nav, array $pageMap): ConversionResult
    {
        return new ConversionResult(
            conversion_id: 'test',
            org_id: 'test-org',
            source_url: 'https://example.com',
            page_map: $pageMap,
            nav: new DataCollection(ResolvedNavItem::class, $nav),
            failures: new DataCollection(ConversionFailure::class, []),
            block_issues_by_slug: [],
            status: ConversionStatus::Completed,
            brand: new Brand(logo_source: 'flag', logo_asset_ref: null),
            style_brief: new GlobalStyleBrief(brand_voice: '', palette: [], layout_conventions: [], nav: new DataCollection(NavItem::class, [])),
            asset_refs: new DataCollection(AssetRef::class, []),
        );
    }

    private function navResolved(string $label, string $slug, int $order): ResolvedNavItem
    {
        return new ResolvedNavItem($label, $slug, $order, ResolvedNavStatus::Resolved);
    }

    /**
     * @param  array<int, Page>  $pages
     */
    private function findPageById(array $pages, string $id): ?Page
    {
        foreach ($pages as $p) {
            if ($p->id === $id) {
                return $p;
            }
        }

        return null;
    }

    /**
     * @param  array<int, Page>  $pages
     */
    private function findPageBySlug(array $pages, string $slug): ?Page
    {
        foreach ($pages as $p) {
            if ($p->slug === $slug) {
                return $p;
            }
        }

        return null;
    }
}
