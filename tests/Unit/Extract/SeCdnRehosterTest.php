<?php

declare(strict_types=1);

namespace Tests\Unit\Extract;

use App\Data\AssetRef;
use App\Services\Extract\AssetUploader;
use App\Services\Extract\ScrapedPage;
use App\Services\Extract\SeCdnRehoster;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\Extract\FakeAssetUploader;
use Tests\TestCase;

// The rebuilt site must have zero live SportsEngine dependency at serve-time:
// every SE-CDN asset URL in a scraped page must be re-hosted to S3. These
// tests pin both halves of that contract:
//   1. SE-CDN hosts get re-hosted; non-SE hosts are left alone.
//   2. Per-asset upload failures are swallowed (a missing image isn't a
//      page failure), but the found-vs-rehosted counts surface so a partial
//      loss isn't invisible.
final class SeCdnRehosterTest extends TestCase
{
    #[Test]
    public function rehosts_se_cdn_hosts_and_leaves_non_se_urls_alone(): void
    {
        $uploader = new FakeAssetUploader;
        $rehoster = new SeCdnRehoster($uploader);

        $scrape = new ScrapedPage(
            url: 'https://example.sportngin.com/about',
            title: 'About',
            markdown: implode("\n", [
                '![banner](https://cdn1.sportngin.com/attachments/photo/123/banner.jpg)',
                'See [logo](https://cdn4.sportngin.com/attachments/logo_graphic/456/iron.png).',
                'And [hero](https://app-assets1.sportngin.com/uploads/hero/789.jpg).',
                'Plus [favicon](https://assets.ngin.com/site_files/13992/favicon.ico).',
                // External hosts — should NOT be re-hosted.
                'External photo: https://images.example.com/something.jpg',
                'Unrelated CDN: https://cdn.cloudflare.com/asset.png',
            ]),
            html: '<img src="https://cdn2.sportngin.com/attachments/photo/999/inline.png" />',
            image_urls: [
                'https://cdn3.sportngin.com/attachments/photo/111/from-images-list.png',
                'https://example.com/not-sportsengine.png',
            ],
        );

        $result = $rehoster->rehost($scrape, 'ngin-13992');

        $this->assertSame(6, $result['found'], 'six unique SE-CDN urls across markdown+html+images');
        $this->assertSame(6, $result['rehosted']);
        $this->assertCount(6, $result['refs']);

        $sources = array_map(static fn (AssetRef $r): ?string => $r->source_url, $result['refs']);
        sort($sources);
        $this->assertSame([
            'https://app-assets1.sportngin.com/uploads/hero/789.jpg',
            'https://assets.ngin.com/site_files/13992/favicon.ico',
            'https://cdn1.sportngin.com/attachments/photo/123/banner.jpg',
            'https://cdn2.sportngin.com/attachments/photo/999/inline.png',
            'https://cdn3.sportngin.com/attachments/photo/111/from-images-list.png',
            'https://cdn4.sportngin.com/attachments/logo_graphic/456/iron.png',
        ], $sources);

        foreach ($result['refs'] as $ref) {
            $this->assertStringStartsWith('s3://', $ref->s3_key);
        }

        // Uploader was hit ONLY for the SE-CDN urls — never the externals.
        $uploadedSources = array_column($uploader->uploads, 'source_url');
        foreach ($uploadedSources as $source) {
            $this->assertNotNull($source);
            $host = (string) parse_url($source, PHP_URL_HOST);
            $this->assertTrue(
                str_ends_with($host, '.sportngin.com') || $host === 'sportngin.com' || $host === 'assets.ngin.com',
                "non-SE-CDN host {$host} should not have been uploaded",
            );
        }
        $this->assertCount(6, $uploadedSources);
    }

    #[Test]
    public function deduplicates_same_asset_referenced_with_different_query_strings(): void
    {
        $uploader = new FakeAssetUploader;
        $rehoster = new SeCdnRehoster($uploader);

        $scrape = new ScrapedPage(
            url: 'https://example.sportngin.com/photos',
            title: 'Photos',
            markdown: implode("\n", [
                'https://cdn1.sportngin.com/attachments/photo/1/x.png?v=1',
                'https://cdn1.sportngin.com/attachments/photo/1/x.png?v=2',
                'https://cdn1.sportngin.com/attachments/photo/1/x.png#anchor',
            ]),
            html: '',
        );

        $result = $rehoster->rehost($scrape, 'ngin-1');

        // All three normalise to the same canonical URL → one re-host.
        $this->assertSame(1, $result['found']);
        $this->assertSame(1, $result['rehosted']);
        $this->assertCount(1, $result['refs']);
    }

    #[Test]
    public function per_asset_upload_failures_are_swallowed_but_counted(): void
    {
        // An uploader that throws on every other call — exactly half the
        // assets land in S3, the other half are silently dropped.
        $uploader = new class implements AssetUploader
        {
            public int $calls = 0;

            public function putFromUrl(string $sourceUrl, string $orgId, string $kind): AssetRef
            {
                $this->calls++;
                if ($this->calls % 2 === 0) {
                    throw new RuntimeException('simulated upload failure');
                }

                return new AssetRef(
                    s3_key: 's3://fake/'.$orgId.'/'.$kind.'/'.sha1($sourceUrl),
                    mime_type: 'image/png',
                    source_url: $sourceUrl,
                );
            }

            public function putContent(string $content, string $mimeType, string $orgId, string $kind, string $name): AssetRef
            {
                throw new RuntimeException('not used in this test');
            }
        };

        $rehoster = new SeCdnRehoster($uploader);

        $scrape = new ScrapedPage(
            url: 'https://example.sportngin.com/gallery',
            title: 'Gallery',
            markdown: '',
            html: '',
            image_urls: [
                'https://cdn1.sportngin.com/attachments/photo/1/a.png',
                'https://cdn1.sportngin.com/attachments/photo/2/b.png',
                'https://cdn1.sportngin.com/attachments/photo/3/c.png',
                'https://cdn1.sportngin.com/attachments/photo/4/d.png',
            ],
        );

        $result = $rehoster->rehost($scrape, 'ngin-1');

        // The page has 4 SE-CDN assets; the uploader fails on every other
        // call → 2 land, 2 are lost. The rehoster does NOT raise — but the
        // counts MUST show the gap so the extractor can surface a Manifest
        // flag.
        $this->assertSame(4, $result['found']);
        $this->assertSame(2, $result['rehosted']);
        $this->assertCount(2, $result['refs']);
    }

    #[Test]
    public function ignores_pages_with_no_se_cdn_urls(): void
    {
        $uploader = new FakeAssetUploader;
        $rehoster = new SeCdnRehoster($uploader);

        $scrape = new ScrapedPage(
            url: 'https://example.sportngin.com/text-only',
            title: 'Text Only',
            markdown: 'Just a paragraph with [an external link](https://wikipedia.org/Sport).',
            html: '<p>plain text</p>',
        );

        $result = $rehoster->rehost($scrape, 'ngin-1');

        $this->assertSame(0, $result['found']);
        $this->assertSame(0, $result['rehosted']);
        $this->assertSame([], $result['refs']);
        $this->assertSame([], $uploader->uploads);
    }
}
