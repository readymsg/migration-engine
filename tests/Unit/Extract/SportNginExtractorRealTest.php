<?php

declare(strict_types=1);

namespace Tests\Unit\Extract;

use App\Data\AssetRef;
use App\Data\ContentRef;
use App\Data\NavNode;
use App\Services\Extract\BrandExtractor;
use App\Services\Extract\ScrapedPage;
use App\Services\Extract\SeCdnRehoster;
use App\Services\Extract\SportNginExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Extract\FakeAssetUploader;
use Tests\Support\Extract\FakeFirecrawlClient;
use Tests\Support\Extract\FixtureHtmlFetcher;
use Tests\Support\Extract\FixtureRootNavFetcher;
use Tests\TestCase;

// Tests against the real SportsEngine sites we recon'd. NEVER hits the
// live network — every external dep is a Fake/Fixture pre-loaded from the
// JSON / HTML we saved in tests/Fixtures/rootnav/real/.
final class SportNginExtractorRealTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/rootnav/real';

    #[Test]
    public function builds_manifest_for_itasca_stthomassoccer(): void
    {
        $html = new FixtureHtmlFetcher;
        $html->preloadFromFile(
            requestedUrl: 'https://www.stthomassoccer.com/',
            finalUrl: 'https://www.stthomassoccer.com/',
            path: self::FIXTURES.'/stthomassoccer.homepage.html',
        );

        $nav = new FixtureRootNavFetcher;
        // Homepage rootNav (id=2901070); siblings = the top-level nav.
        $nav->preloadFromFile(2901070, self::FIXTURES.'/stthomassoccer.rootnav.json');
        // BFS expansion fixture: About Us has has_child=11 — only this child
        // node has a saved fixture, so only it expands; the others (Programs,
        // Tournaments, Resources) hit the fetcher's "no fixture" throw and
        // are handled gracefully with empty children.
        $nav->preloadFromFile(2901073, self::FIXTURES.'/stthomassoccer.node.2901073.json');

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

        $uploader = new FakeAssetUploader;
        $extractor = new SportNginExtractor(
            $html,
            $nav,
            $firecrawl,
            $uploader,
            new BrandExtractor,
            new SeCdnRehoster($uploader),
        );

        $manifest = $extractor->extract('https://www.stthomassoccer.com/');

        // No redirect.
        $this->assertSame('https://www.stthomassoccer.com/', $manifest->source_url);
        $this->assertSame('ngin-13992', $manifest->org_id);
        foreach ($manifest->flags as $flag) {
            $this->assertStringStartsNotWith('redirected:', $flag);
        }

        // Top-level structure: 7 siblings, real labels.
        $topLabels = array_map(
            static fn (NavNode $n): string => $n->label,
            $manifest->structure->nav->items(),
        );
        $this->assertSame(
            ['Home', 'About Us', 'Programs', 'Referees', 'Tournaments', 'Resources', 'Dibs'],
            $topLabels,
        );

        // node_type carried through, kind derived from it.
        /** @var NavNode $home */
        $home = $manifest->structure->nav->items()[0];
        $this->assertSame('Page', $home->node_type);
        $this->assertSame('page', $home->kind);
        $this->assertSame(2901070, $home->page_node_id);

        // BFS only expanded the one node we pre-loaded.
        /** @var NavNode $aboutUs */
        $aboutUs = $manifest->structure->nav->items()[1];
        $this->assertSame('About Us', $aboutUs->label);
        $this->assertSame(11, $aboutUs->children->count());
        /** @var NavNode $programs */
        $programs = $manifest->structure->nav->items()[2];
        $this->assertSame('Programs', $programs->label);
        $this->assertSame(0, $programs->children->count(), 'unloaded node should not expand');

        // SE external-link shapes — Swag/Spirit Wear is a LinkNode child of
        // About Us pointing at the org's shop; Dibs is the hardcoded toolsLink
        // sibling at the top level. Both stay in the tree as external.
        $swag = $this->findByLabel($aboutUs->children->items(), 'Swag/ Spirit Wear');
        $this->assertNotNull($swag);
        $this->assertSame('LinkNode', $swag->node_type);
        $this->assertSame('external', $swag->kind);
        $this->assertSame('external_link', $swag->external_subtype);

        $dibs = $this->findByLabel($manifest->structure->nav->items(), 'Dibs');
        $this->assertNotNull($dibs);
        $this->assertNull($dibs->node_type);
        $this->assertNull($dibs->page_node_id, 'toolsLink id is not page_node_<int>');
        $this->assertSame('external', $dibs->kind);
        $this->assertSame('se_tool', $dibs->external_subtype);

        // pages_total counts the whole walked tree, not just top-level.
        $this->assertSame(7 + 11, $manifest->structure->pages_total);

        // v1 scope cut: provisioning is not extracted. DTO field is null.
        $this->assertNull($manifest->provisioning);

        // Brand: header banner_graphic exists in the homepage HTML.
        $this->assertSame('header', $manifest->brand->logo_source);
        $this->assertNotNull($manifest->brand->logo_asset_ref);
        $this->assertStringStartsWith('s3://', $manifest->brand->logo_asset_ref);

        // Scrape: we preloaded only Home; that's the only ContentRef.
        $this->assertCount(1, $manifest->content_refs);
        /** @var ContentRef $contentRef */
        $contentRef = $manifest->content_refs->items()[0];
        $this->assertSame('https://www.stthomassoccer.com/page/show/2901070-home', $contentRef->url);
        $this->assertStringStartsWith('s3://', $contentRef->scrape_ref);

        // Asset refs: 1 logo + 1 scrape; all s3.
        $this->assertCount(2, $manifest->asset_refs);
        foreach ($manifest->asset_refs as $asset) {
            /** @var AssetRef $asset */
            $this->assertStringStartsWith('s3://', $asset->s3_key);
        }

        // Confidence — structure (0.5) + brand non-flag (0.5) = 1.0 under the
        // v1 site-rebuild rubric.
        $this->assertSame(1.0, $manifest->confidence);
    }

    #[Test]
    public function builds_manifest_for_waterworld_strikersbaseball_and_captures_redirect(): void
    {
        $html = new FixtureHtmlFetcher;
        // The input URL is strikersbaseball.ca but the live server 301s to
        // langdondiamonds.ca — the manifest must record the final URL.
        $html->preloadFromFile(
            requestedUrl: 'https://www.strikersbaseball.ca/',
            finalUrl: 'https://www.langdondiamonds.ca/',
            path: self::FIXTURES.'/strikersbaseball.homepage.html',
        );

        $nav = new FixtureRootNavFetcher;
        // No `var currentId` in waterworld theme — extractor falls back to
        // trying every page_node_<id> reference in the HTML. The first id
        // (7499825, the root parent) returns 401 live; we model that here
        // by simply not preloading it, so the fetcher throws "no fixture"
        // and the extractor moves on to the next candidate, 7507227 (Home).
        $nav->preloadFromFile(7507227, self::FIXTURES.'/strikersbaseball.rootnav.json');
        $nav->preloadFromFile(7507229, self::FIXTURES.'/strikersbaseball.node.7507229.json');

        $firecrawl = new FakeFirecrawlClient;
        // Note: the URL is built against the FINAL origin (langdondiamonds),
        // not the requested one (strikersbaseball). Pinning the FakeFirecrawl
        // to the final-origin URL proves the extractor used the post-redirect
        // origin to build absolute URLs.
        $firecrawl->preload(
            'https://www.langdondiamonds.ca/home',
            new ScrapedPage(
                url: 'https://www.langdondiamonds.ca/home',
                title: 'Home',
                markdown: '# Home',
                html: '<h1>Home</h1>',
            ),
        );

        $uploader = new FakeAssetUploader;
        $extractor = new SportNginExtractor(
            $html,
            $nav,
            $firecrawl,
            $uploader,
            new BrandExtractor,
            new SeCdnRehoster($uploader),
        );

        $manifest = $extractor->extract('https://www.strikersbaseball.ca/');

        // Redirect captured.
        $this->assertSame('https://www.langdondiamonds.ca/', $manifest->source_url);
        $this->assertContains(
            'redirected: https://www.strikersbaseball.ca/ -> https://www.langdondiamonds.ca/',
            $manifest->flags,
        );
        $this->assertSame('ngin-65581', $manifest->org_id);

        // 13 top-level siblings (waterworld site has a deeper menu).
        $this->assertCount(13, $manifest->structure->nav);

        // BFS expanded the one node we pre-loaded.
        $aboutUs = null;
        foreach ($manifest->structure->nav->items() as $node) {
            /** @var NavNode $node */
            if ($node->label === 'About Us') {
                $aboutUs = $node;
                break;
            }
        }
        $this->assertNotNull($aboutUs);
        $this->assertSame(6, $aboutUs->children->count());

        // Same Dibs toolsLink sibling appears here — SE injects it on every
        // site, regardless of theme.
        $dibs = $this->findByLabel($manifest->structure->nav->items(), 'Dibs');
        $this->assertNotNull($dibs);
        $this->assertSame('external', $dibs->kind);
        $this->assertSame('se_tool', $dibs->external_subtype);

        // Calendar at top-level is a real Calendar node — sanity that the
        // existing dynamic_* classification still works alongside external.
        $calendar = $this->findByLabel($manifest->structure->nav->items(), 'Calendar');
        $this->assertNotNull($calendar);
        $this->assertSame('Calendar', $calendar->node_type);
        $this->assertSame('dynamic_calendar', $calendar->kind);
        $this->assertNull($calendar->external_subtype);

        // v1 scope cut: provisioning is not extracted, even though this site
        // has a "Langdon Leagues" top-level we could have walked.
        $this->assertNull($manifest->provisioning);

        // Brand: no banner_graphic in this site's homepage, so the extractor
        // falls back inside the "header" rung to logo_graphic (iron_horse_*).
        $this->assertSame('header', $manifest->brand->logo_source);
        $this->assertNotNull($manifest->brand->logo_asset_ref);

        // Scrape against the post-redirect origin.
        $this->assertCount(1, $manifest->content_refs);
        /** @var ContentRef $contentRef */
        $contentRef = $manifest->content_refs->items()[0];
        $this->assertSame('https://www.langdondiamonds.ca/home', $contentRef->url);

        // FakeAssetUploader saw a logo upload + scrape upload — all s3.
        $kinds = array_count_values(array_column($uploader->uploads, 'kind'));
        $this->assertSame(1, $kinds['logos'] ?? 0);
        $this->assertSame(1, $kinds['scrapes'] ?? 0);
    }

    /**
     * @param  array<int, NavNode>  $nodes
     */
    private function findByLabel(array $nodes, string $label): ?NavNode
    {
        foreach ($nodes as $node) {
            if ($node->label === $label) {
                return $node;
            }
        }

        return null;
    }
}
