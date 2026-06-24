<?php

declare(strict_types=1);

namespace Tests\Unit\Extract;

use App\Data\ContentExtractionFailure;
use App\Data\Manifest;
use App\Data\NavNode;
use App\Services\Extract\AssetUploader;
use App\Data\AssetRef;
use App\Services\Extract\BrandExtractor;
use App\Services\Extract\S3AssetUploader;
use App\Services\Extract\ScrapedPage;
use App\Services\Extract\SeCdnRehoster;
use App\Services\Extract\SportNginExtractor;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\Extract\FakeAssetUploader;
use Tests\Support\Extract\FakeFirecrawlClient;
use Tests\Support\Extract\FixtureHtmlFetcher;
use Tests\Support\Extract\FixtureRootNavFetcher;
use Tests\TestCase;

// The faithful-rebuild guarantee for INGEST: every kind=page nav node with a
// URL produces EITHER a ContentRef (scrape succeeded) OR an explicit
// ContentExtractionFailure (scrape returned null OR threw). A silent absence
// — a kind=page-with-url node that's missing from both — would be worse than
// a visible failure: a reviewer can't see what they need to re-run.
//
// These tests pin the contract using the real stthomassoccer fixture (16
// kind=page-with-url nodes) and FakeFirecrawlClient's preload/failHard
// affordances. The CDN-counter wiring is exercised at the end so it's clear
// the rehoster's found/rehosted totals flow through into the Manifest.
final class ContentExtractionFailureTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/rootnav/real';

    private const HOME_URL = 'https://www.stthomassoccer.com/page/show/2901070-home';

    private const STTHOMAS_KIND_PAGE_WITH_URL_COUNT = 16;

    #[Test]
    public function null_scrape_produces_explicit_failure_with_returned_null_reason(): void
    {
        // Preload Home; let every other kind=page node hit a no-preload
        // scrape that returns null. We expect 1 content_ref + 15 failures,
        // all of them marked firecrawl_returned_null.
        $manifest = $this->buildManifest(
            preload: [self::HOME_URL => 'Home'],
            failHard: [],
        );

        $this->assertCount(1, $manifest->content_refs);
        $this->assertNotNull($manifest->content_failures);
        $this->assertCount(15, $manifest->content_failures);

        foreach ($manifest->content_failures->items() as $failure) {
            /** @var ContentExtractionFailure $failure */
            $this->assertSame('firecrawl_returned_null', $failure->reason);
            $this->assertNotSame(self::HOME_URL, $failure->url, 'Home scraped successfully — should not appear in failures');
            // Every failure must carry enough metadata for a reviewer to act.
            $this->assertNotSame('', $failure->url);
            $this->assertNotSame('', $failure->page_title);
        }
    }

    #[Test]
    public function thrown_scrape_produces_failure_with_firecrawl_threw_reason(): void
    {
        // failHard on Home → scrape throws. Other 15 pages return null.
        $manifest = $this->buildManifest(
            preload: [],
            failHard: [self::HOME_URL],
        );

        $this->assertCount(0, $manifest->content_refs);
        $this->assertNotNull($manifest->content_failures);
        $this->assertCount(self::STTHOMAS_KIND_PAGE_WITH_URL_COUNT, $manifest->content_failures);

        $thrown = array_values(array_filter(
            $manifest->content_failures->items(),
            static fn (ContentExtractionFailure $f): bool => str_starts_with($f->reason, 'firecrawl_threw:'),
        ));
        $this->assertCount(1, $thrown, 'exactly one failure should be marked firecrawl_threw');
        $this->assertSame(self::HOME_URL, $thrown[0]->url);
        $this->assertStringContainsString('simulated failure', $thrown[0]->reason);
    }

    #[Test]
    public function counts_tie_out_content_refs_plus_failures_equals_kind_page_with_url_nodes(): void
    {
        // Mixed scenario: preload Home (success), failHard /board (throws),
        // leave the other 14 untouched (return null). The reconciliation
        // invariant — content_refs + content_failures == kind=page-with-url
        // nodes — must hold regardless of which paths each page takes.
        $manifest = $this->buildManifest(
            preload: [self::HOME_URL => 'Home'],
            failHard: ['https://www.stthomassoccer.com/board'],
        );

        $kindPageWithUrl = $this->countKindPageWithUrl($manifest);
        $this->assertSame(self::STTHOMAS_KIND_PAGE_WITH_URL_COUNT, $kindPageWithUrl);

        $this->assertNotNull($manifest->content_failures);
        $refs = $manifest->content_refs->count();
        $fails = $manifest->content_failures->count();
        $this->assertSame(
            $kindPageWithUrl,
            $refs + $fails,
            "Tie-out violated: {$refs} content_refs + {$fails} content_failures != {$kindPageWithUrl} kind=page-with-url nodes",
        );

        // And every node URL appears in exactly one bucket — neither
        // silently absent nor double-counted.
        $refUrls = array_map(static fn ($r): string => $r->url, $manifest->content_refs->items());
        $failUrls = array_map(static fn (ContentExtractionFailure $f): string => $f->url, $manifest->content_failures->items());
        $this->assertSame(
            $kindPageWithUrl,
            count(array_unique([...$refUrls, ...$failUrls])),
            'every kind=page-with-url node must appear in exactly one of content_refs or content_failures',
        );
    }

    #[Test]
    public function partial_extraction_surfaces_a_flag_with_failure_count(): void
    {
        $manifest = $this->buildManifest(
            preload: [self::HOME_URL => 'Home'],
            failHard: [],
        );

        $this->assertContains(
            'content_extraction_partial: 15 page(s) failed',
            $manifest->flags,
        );
    }

    #[Test]
    public function fully_successful_extraction_has_no_failure_flag_or_failures(): void
    {
        // Preload ALL 16 URLs → zero failures, no partial flag.
        $allSeen = $this->observedScrapeUrls();
        $preload = [];
        foreach ($allSeen as $url) {
            $preload[$url] = 'Title for '.$url;
        }

        $manifest = $this->buildManifest(preload: $preload, failHard: []);

        $this->assertCount(self::STTHOMAS_KIND_PAGE_WITH_URL_COUNT, $manifest->content_refs);
        $this->assertNotNull($manifest->content_failures);
        $this->assertCount(0, $manifest->content_failures);

        foreach ($manifest->flags as $flag) {
            $this->assertStringStartsNotWith('content_extraction_partial:', $flag);
        }
    }

    #[Test]
    public function cdn_rehost_counts_flow_from_rehoster_into_manifest(): void
    {
        // A scrape with three SE-CDN assets — proves the extractor sums
        // rehost stats from across pages and surfaces them on the Manifest.
        $scrapeWithCdn = new ScrapedPage(
            url: self::HOME_URL,
            title: 'Home',
            markdown: implode("\n", [
                '![logo](https://cdn1.sportngin.com/attachments/photo/1/a.png)',
                '![banner](https://cdn2.sportngin.com/attachments/photo/2/b.png)',
            ]),
            html: '<img src="https://assets.ngin.com/site_files/13992/c.png" />',
        );

        $manifest = $this->buildManifest(
            preload: [self::HOME_URL => $scrapeWithCdn],
            failHard: [],
        );

        $this->assertSame(3, $manifest->cdn_assets_found);
        $this->assertSame(3, $manifest->cdn_assets_rehosted);
        foreach ($manifest->flags as $flag) {
            $this->assertStringStartsNotWith('cdn_rehost_partial:', $flag, 'no partial flag when every asset re-hosted');
        }
    }

    #[Test]
    public function cdn_rehost_partial_flag_appears_when_uploader_loses_assets(): void
    {
        $scrapeWithCdn = new ScrapedPage(
            url: self::HOME_URL,
            title: 'Home',
            markdown: '',
            html: '',
            image_urls: [
                'https://cdn1.sportngin.com/attachments/photo/1/a.png',
                'https://cdn1.sportngin.com/attachments/photo/2/b.png',
                'https://cdn1.sportngin.com/attachments/photo/3/c.png',
                'https://cdn1.sportngin.com/attachments/photo/4/d.png',
            ],
        );

        // Uploader fails on every other CDN call; putContent (the scrape
        // JSON upload) is allowed so the content extraction itself still
        // succeeds — the failure is ONLY in the asset re-host path.
        $uploader = new class implements AssetUploader
        {
            public int $fromUrlCalls = 0;

            public function putFromUrl(string $sourceUrl, string $orgId, string $kind): AssetRef
            {
                $this->fromUrlCalls++;
                if ($this->fromUrlCalls % 2 === 0) {
                    throw new RuntimeException('simulated rehost failure');
                }

                return new AssetRef(
                    s3_key: 's3://fake/'.$orgId.'/'.$kind.'/'.sha1($sourceUrl),
                    mime_type: 'image/png',
                    source_url: $sourceUrl,
                );
            }

            public function putContent(string $content, string $mimeType, string $orgId, string $kind, string $name): AssetRef
            {
                return new AssetRef(
                    s3_key: 's3://fake/'.$orgId.'/'.$kind.'/'.$name,
                    mime_type: $mimeType,
                    source_url: null,
                    bytes: strlen($content),
                );
            }
        };

        $manifest = $this->buildManifest(
            preload: [self::HOME_URL => $scrapeWithCdn],
            failHard: [],
            uploader: $uploader,
        );

        $this->assertSame(4, $manifest->cdn_assets_found);
        $this->assertSame(2, $manifest->cdn_assets_rehosted);
        $this->assertContains('cdn_rehost_partial: 2/4 assets re-hosted', $manifest->flags);

        // And critically — the page's body extraction was NOT marked failed.
        // The rebuilt site lost two images, not a whole page.
        $this->assertCount(1, $manifest->content_refs);
    }

    #[Test]
    public function brand_upload_failure_does_not_abort_extraction_it_flags_and_falls_back_to_logo_source_flag(): void
    {
        // Brand logo path: BrandExtractor calls $uploader->putFromUrl(..., 'logos').
        // S3AssetUploader now throws on a failed write — but a brand upload
        // failure is a SOFT signal, not a fatal abort. Extraction must:
        //   1. complete without throwing,
        //   2. surface a 'brand_upload_failed: <reason>' flag on the Manifest,
        //   3. fall back to the existing 'flag' (no-logo) brand,
        //   4. STILL capture page content — the scrape path is independent of
        //      the brand path and shouldn't be punished for a brand failure.
        //
        // The failing disk here fails ONLY for /logos/ writes so the scrape
        // path can demonstrate it still works end-to-end.
        $failingDisk = $this->createMock(Filesystem::class);
        $failingDisk->method('put')->willReturnCallback(
            static fn (string $path): bool => ! str_contains($path, '/logos/'),
        );
        /** @var FilesystemManager $manager */
        $manager = $this->app->make('filesystem');
        $manager->set('brand-failing-disk', $failingDisk);

        $manifest = $this->buildManifest(
            preload: [self::HOME_URL => 'Home'],
            failHard: [],
            uploader: new S3AssetUploader(disk: 'brand-failing-disk'),
        );

        // (1) extraction completed — getting here without throwing IS the assertion.
        // (2) brand_upload_failed flag is set, carries the reason.
        $brandFlags = array_values(array_filter(
            $manifest->flags,
            static fn (string $f): bool => str_starts_with($f, 'brand_upload_failed:'),
        ));
        $this->assertCount(1, $brandFlags, 'brand_upload_failed flag must be set on the Manifest');
        $this->assertStringContainsString('returned false', $brandFlags[0]);

        // (3) flag-fallback brand.
        $this->assertSame('flag', $manifest->brand->logo_source);
        $this->assertNull($manifest->brand->logo_asset_ref);

        // (4) content still captured — scrape path is unaffected by the brand failure.
        $this->assertCount(1, $manifest->content_refs, 'Home preload should still produce its ContentRef');
        $homeRefs = array_values(array_filter(
            $manifest->content_refs->items(),
            static fn ($r): bool => $r->url === self::HOME_URL,
        ));
        $this->assertCount(1, $homeRefs);
    }

    #[Test]
    public function disk_put_returning_false_surfaces_as_scrape_upload_failed_not_phantom_content_ref(): void
    {
        // Wire S3AssetUploader at a disk whose put() returns false for
        // scrape paths — the exact silent-no-op the previous live tbirdhoops
        // probe was bitten by. The brand-logo upload (separate kind) is
        // allowed to succeed so this test stays scoped to the scrape path;
        // brand-upload robustness is a separate concern.
        $failingDisk = $this->createMock(Filesystem::class);
        $failingDisk->method('put')->willReturnCallback(
            static fn (string $path): bool => ! str_contains($path, '/scrapes/'),
        );
        /** @var FilesystemManager $manager */
        $manager = $this->app->make('filesystem');
        $manager->set('extract-failing-disk', $failingDisk);

        $manifest = $this->buildManifest(
            preload: [self::HOME_URL => 'Home'],
            failHard: [],
            uploader: new S3AssetUploader(disk: 'extract-failing-disk'),
        );

        $this->assertCount(0, $manifest->content_refs, 'no phantom ContentRef when the disk put returned false');

        $this->assertNotNull($manifest->content_failures);
        $homeFailures = array_values(array_filter(
            $manifest->content_failures->items(),
            static fn (ContentExtractionFailure $f): bool => $f->url === self::HOME_URL,
        ));
        $this->assertCount(1, $homeFailures, 'Home (the only preloaded URL) must surface as exactly one failure');
        $this->assertStringStartsWith(
            'scrape_upload_failed:',
            $homeFailures[0]->reason,
            'reason must identify this as an upload failure, not a scrape failure',
        );
        $this->assertStringContainsString('returned false', $homeFailures[0]->reason);

        // Reconciliation invariant still holds — every kind=page-with-url
        // node is in exactly one bucket.
        $kindPageWithUrl = $this->countKindPageWithUrl($manifest);
        $this->assertSame(
            $kindPageWithUrl,
            $manifest->content_refs->count() + $manifest->content_failures->count(),
            'tie-out must still hold under disk failure',
        );
    }

    /**
     * @param  array<string, string|ScrapedPage>  $preload  url => title (or full ScrapedPage)
     * @param  array<int, string>  $failHard  urls scrape() should throw for
     */
    private function buildManifest(array $preload, array $failHard, ?AssetUploader $uploader = null): Manifest
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
        foreach ($preload as $url => $titleOrScrape) {
            $page = $titleOrScrape instanceof ScrapedPage
                ? $titleOrScrape
                : new ScrapedPage(url: $url, title: $titleOrScrape, markdown: '# '.$titleOrScrape, html: '');
            $firecrawl->preload($url, $page);
        }
        foreach ($failHard as $url) {
            $firecrawl->failHard($url);
        }

        $uploader ??= new FakeAssetUploader;
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
     * The set of absolute URLs the extractor will attempt to scrape against
     * the stthomas fixture — derived empirically by running the extractor
     * once with an empty FakeFirecrawlClient and reading $firecrawl->seen.
     * Hard-coded here so the test doesn't need to do a two-pass run.
     *
     * @return array<int, string>
     */
    private function observedScrapeUrls(): array
    {
        return [
            self::HOME_URL,
            'https://www.stthomassoccer.com/connect',
            'https://www.stthomassoccer.com/page/show/3062146-contact-the-st-thomas-soccer-club',
            'https://www.stthomassoccer.com/board',
            'https://www.stthomassoccer.com/coaches',
            'https://www.stthomassoccer.com/facilities',
            'https://www.stthomassoccer.com/parents',
            'https://www.stthomassoccer.com/social-media',
            'https://www.stthomassoccer.com/page/show/2964540-sponsors',
            'https://www.stthomassoccer.com/page/show/3013214-code-of-ethics-and-conduct',
            'https://www.stthomassoccer.com/page/show/3013215-discipline-policy',
            'https://www.stthomassoccer.com/documents',
            'https://www.stthomassoccer.com/page/show/3060737-programs',
            'https://www.stthomassoccer.com/page/show/3246748-referees',
            'https://www.stthomassoccer.com/tourneys',
            'https://www.stthomassoccer.com/page/show/3013257-resources',
        ];
    }

    private function countKindPageWithUrl(Manifest $manifest): int
    {
        $count = 0;
        $this->walk(
            $manifest->structure->nav->items(),
            function (NavNode $n) use (&$count): void {
                if ($n->kind === 'page' && $n->url !== null) {
                    $count++;
                }
            },
        );

        return $count;
    }

    /**
     * @param  array<int, NavNode>  $nodes
     * @param  callable(NavNode):void  $visitor
     */
    private function walk(array $nodes, callable $visitor): void
    {
        foreach ($nodes as $node) {
            $visitor($node);
            /** @var array<int, NavNode> $children */
            $children = $node->children->items();
            $this->walk($children, $visitor);
        }
    }
}
