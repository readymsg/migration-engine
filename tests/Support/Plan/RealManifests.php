<?php

declare(strict_types=1);

namespace Tests\Support\Plan;

use App\Data\Manifest;
use App\Services\Extract\BrandExtractor;
use App\Services\Extract\ScrapedPage;
use App\Services\Extract\SeCdnRehoster;
use App\Services\Extract\SportNginExtractor;
use Tests\Support\Extract\FakeAssetUploader;
use Tests\Support\Extract\FakeFirecrawlClient;
use Tests\Support\Extract\FixtureHtmlFetcher;
use Tests\Support\Extract\FixtureRootNavFetcher;

// Builds the two real-fixture Manifests used by PlannerTest. Lives in test
// support so the planner test doesn't have to know about the extractor's
// internal fakes — and so we don't recreate the setup in two test files.
final class RealManifests
{
    private const FIXTURES = __DIR__.'/../../Fixtures/rootnav/real';

    public static function stthomas(): Manifest
    {
        $html = new FixtureHtmlFetcher;
        $html->preloadFromFile(
            requestedUrl: 'https://www.stthomassoccer.com/',
            finalUrl: 'https://www.stthomassoccer.com/',
            path: self::FIXTURES.'/stthomassoccer.homepage.html',
        );

        $nav = new FixtureRootNavFetcher;
        $nav->preloadFromFile(2901070, self::FIXTURES.'/stthomassoccer.rootnav.json');
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

        return $extractor->extract('https://www.stthomassoccer.com/');
    }

    public static function tenacityvolleyball(): Manifest
    {
        $html = new FixtureHtmlFetcher;
        $html->preloadFromFile(
            requestedUrl: 'https://www.tenacityvolleyball.com/',
            finalUrl: 'https://www.tenacityvolleyball.com/',
            path: self::FIXTURES.'/tenacityvolleyball.homepage.html',
        );

        $nav = new FixtureRootNavFetcher;
        // Homepage node + every BFS-expansion fixture we have on disk so
        // the realised tree matches what production would see.
        $nav->preloadFromFile(8115909, self::FIXTURES.'/tenacityvolleyball.rootnav.json');
        $nav->preloadFromFile(8115910, self::FIXTURES.'/tenacityvolleyball.node.8115910.json'); // About Us
        $nav->preloadFromFile(8115917, self::FIXTURES.'/tenacityvolleyball.node.8115917.json'); // News
        $nav->preloadFromFile(8116200, self::FIXTURES.'/tenacityvolleyball.node.8116200.json'); // Teams (has_child=3)
        $nav->preloadFromFile(8304450, self::FIXTURES.'/tenacityvolleyball.node.8304450.json'); // Recruiting
        $nav->preloadFromFile(8611434, self::FIXTURES.'/tenacityvolleyball.node.8611434.json'); // Recognition

        $uploader = new FakeAssetUploader;
        $extractor = new SportNginExtractor(
            $html,
            $nav,
            new FakeFirecrawlClient,    // PLAN-only — no scraping in this fixture
            $uploader,
            new BrandExtractor,
            new SeCdnRehoster($uploader),
        );

        return $extractor->extract('https://www.tenacityvolleyball.com/');
    }

    public static function surprisevolleyballacademy(): Manifest
    {
        $html = new FixtureHtmlFetcher;
        $html->preloadFromFile(
            requestedUrl: 'https://www.surprisevolleyballacademy.org/',
            finalUrl: 'https://www.surprisevolleyballacademy.org/',
            path: self::FIXTURES.'/surprisevolleyballacademy.homepage.html',
        );

        $nav = new FixtureRootNavFetcher;
        $nav->preloadFromFile(1738735, self::FIXTURES.'/surprisevolleyballacademy.rootnav.json');
        $nav->preloadFromFile(2090298, self::FIXTURES.'/surprisevolleyballacademy.node.2090298.json'); // About Us
        $nav->preloadFromFile(2177484, self::FIXTURES.'/surprisevolleyballacademy.node.2177484.json'); // Tryouts/Open House — contains the "Sports Engine" link

        $uploader = new FakeAssetUploader;
        $extractor = new SportNginExtractor(
            $html,
            $nav,
            new FakeFirecrawlClient,
            $uploader,
            new BrandExtractor,
            new SeCdnRehoster($uploader),
        );

        return $extractor->extract('https://www.surprisevolleyballacademy.org/');
    }

    public static function langdondiamonds(): Manifest
    {
        $html = new FixtureHtmlFetcher;
        $html->preloadFromFile(
            requestedUrl: 'https://www.strikersbaseball.ca/',
            finalUrl: 'https://www.langdondiamonds.ca/',
            path: self::FIXTURES.'/strikersbaseball.homepage.html',
        );

        $nav = new FixtureRootNavFetcher;
        $nav->preloadFromFile(7507227, self::FIXTURES.'/strikersbaseball.rootnav.json');
        $nav->preloadFromFile(7507229, self::FIXTURES.'/strikersbaseball.node.7507229.json');

        $firecrawl = new FakeFirecrawlClient;
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

        return $extractor->extract('https://www.strikersbaseball.ca/');
    }
}
