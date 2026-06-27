<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\ContentRef;
use App\Services\Generate\ContentLoader;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// ContentLoader read-back coverage.
//
// One test uses the on-disk tbirdhoops corpus at
// storage/app/private/orgs/ngin-63620/scrapes/ — that's the real
// chrome-free Firecrawl output the INGEST slice captured. It proves the
// ContentLoader works end to end against a production-shaped scrape file
// without needing a fresh Firecrawl call.
//
// The remaining tests use Storage::fake() to cover the failure modes
// (missing key, malformed JSON, empty body) deterministically without
// fixture sprawl.
final class ContentLoaderTest extends TestCase
{
    private const FAKE_DISK = 'scrapes-loader-test';

    #[Test]
    public function reads_a_real_chrome_free_tbirdhoops_scrape_from_the_on_disk_corpus(): void
    {
        // The on-disk corpus lives at storage/app/private/orgs/ngin-63620/
        // scrapes/, addressable via the `local` disk (Laravel's
        // storage_path('app/private') root). One file = one captured page.
        // 0c95764f... is the About Us page; we don't hardcode the slug,
        // we just verify the loader returns the body and image urls.
        $aboutUsKey = 'orgs/ngin-63620/scrapes/0c95764f2451659896ccee8d6781771deeca3e50.json';
        $this->assertTrue(
            Storage::disk('local')->exists($aboutUsKey),
            'tbirdhoops on-disk corpus is missing — INGEST slice tests assume this exists'
        );

        $loader = new ContentLoader(disk: 'local');
        $ref = new ContentRef(
            url: 'https://www.tbirdhoops.org/aboutus',
            scrape_ref: 's3://'.$aboutUsKey,
            title: 'About Us',
            nav_path: ['About Us'],
        );

        $loaded = $loader->load($ref);

        $this->assertNotNull($loaded, 'real corpus file must load');
        $this->assertNotEmpty($loaded->markdown);
        $this->assertStringContainsString('Lakota Thunderbird Youth Basketball', $loaded->markdown);
        // Real scrape includes inline images from the SE CDN.
        $this->assertGreaterThan(0, count($loaded->image_urls));
    }

    #[Test]
    public function returns_null_when_scrape_ref_key_does_not_exist_on_disk(): void
    {
        Storage::fake(self::FAKE_DISK);
        $loader = new ContentLoader(disk: self::FAKE_DISK);

        $ref = new ContentRef(
            url: 'https://example.test/missing',
            scrape_ref: 's3://orgs/nope/scrapes/nope.json',
        );

        $this->assertNull($loader->load($ref));
    }

    #[Test]
    public function returns_null_when_body_is_malformed_json(): void
    {
        Storage::fake(self::FAKE_DISK);
        Storage::disk(self::FAKE_DISK)->put(
            'orgs/x/scrapes/bad.json',
            'this is not json {',
        );

        $loader = new ContentLoader(disk: self::FAKE_DISK);
        $ref = new ContentRef(
            url: 'https://example.test/bad',
            scrape_ref: 's3://orgs/x/scrapes/bad.json',
        );

        $this->assertNull($loader->load($ref));
    }

    #[Test]
    public function returns_null_when_markdown_is_missing_or_empty(): void
    {
        // Empty body = functionally same as no body for IR design. The
        // loader normalises this to null so the caller flags the page
        // rather than "designing" from nothing.
        Storage::fake(self::FAKE_DISK);
        Storage::disk(self::FAKE_DISK)->put(
            'orgs/x/scrapes/empty.json',
            json_encode(['url' => 'u', 'title' => 't', 'markdown' => '', 'html' => '', 'image_urls' => []]),
        );

        $loader = new ContentLoader(disk: self::FAKE_DISK);
        $ref = new ContentRef(
            url: 'https://example.test/empty',
            scrape_ref: 's3://orgs/x/scrapes/empty.json',
        );

        $this->assertNull($loader->load($ref));
    }

    #[Test]
    public function strips_s3_logical_prefix_to_get_the_disk_relative_key(): void
    {
        // The extractor writes scrape_ref as "s3://orgs/{org}/scrapes/{x}"
        // regardless of the actual disk. The loader must strip that prefix.
        Storage::fake(self::FAKE_DISK);
        Storage::disk(self::FAKE_DISK)->put(
            'orgs/x/scrapes/ok.json',
            json_encode([
                'url' => 'https://example.test/ok',
                'title' => 'Ok',
                'markdown' => '# Hello',
                'html' => '<h1>Hello</h1>',
                'image_urls' => ['https://example.test/img.jpg'],
            ]),
        );

        $loader = new ContentLoader(disk: self::FAKE_DISK);
        $ref = new ContentRef(
            url: 'https://example.test/ok',
            scrape_ref: 's3://orgs/x/scrapes/ok.json',
        );

        $loaded = $loader->load($ref);
        $this->assertNotNull($loaded);
        $this->assertSame('# Hello', $loaded->markdown);
        $this->assertSame(['https://example.test/img.jpg'], $loaded->image_urls);
    }
}
