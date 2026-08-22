<?php

declare(strict_types=1);

namespace Tests\Unit\Extract;

use App\Data\AssetRef;
use App\Services\Extract\AssetUploader;
use App\Services\Extract\BrandExtractor;
use App\Services\Extract\LogoPaletteExtractor;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Pins BrandExtractor's palette_error semantics — the "why measured
// palette was unavailable" signal that SiteSettingsEmitter consumes
// to emit LOUD fallback diagnostics per slot. Silent fallback on
// primaryColor/neutralColor (contract's highest-value fields) is
// the exact silent-loss surface Slice 3 closes.
final class BrandExtractorPaletteErrorTest extends TestCase
{
    #[Test]
    public function no_palette_extractor_sets_palette_error_to_no_palette_extractor(): void
    {
        // Legacy path: BrandExtractor constructed without a
        // LogoPaletteExtractor (the pre-slice-2 wiring). Any measurement
        // is skipped; palette_error names the wiring gap.
        $extractor = new BrandExtractor(paletteExtractor: null);
        $uploader = new FakeAssetUploader;
        $html = '<html><meta property="og:image" content="https://cdn.example.com/logo.png"></html>';
        $brand = $extractor->extract($html, 'org-1', $uploader);

        $this->assertSame([], $brand->palette);
        $this->assertSame('no_palette_extractor', $brand->palette_error);
    }

    #[Test]
    public function fetch_failure_sets_palette_error_to_logo_fetch_failed_with_status(): void
    {
        Http::fake([
            'cdn.example.com/*' => Http::response('not found', 404),
        ]);
        $extractor = new BrandExtractor(paletteExtractor: new LogoPaletteExtractor);
        $uploader = new FakeAssetUploader;
        $html = '<html><meta property="og:image" content="https://cdn.example.com/logo.png"></html>';
        $brand = $extractor->extract($html, 'org-1', $uploader);

        $this->assertSame([], $brand->palette);
        $this->assertNotNull($brand->palette_error);
        $this->assertStringStartsWith('logo_fetch_failed:', $brand->palette_error);
        $this->assertStringContainsString('404', $brand->palette_error);
    }

    #[Test]
    public function empty_body_sets_palette_error_to_logo_body_empty(): void
    {
        Http::fake([
            'cdn.example.com/*' => Http::response('', 200),
        ]);
        $extractor = new BrandExtractor(paletteExtractor: new LogoPaletteExtractor);
        $uploader = new FakeAssetUploader;
        $html = '<html><meta property="og:image" content="https://cdn.example.com/logo.png"></html>';
        $brand = $extractor->extract($html, 'org-1', $uploader);

        $this->assertSame('logo_body_empty', $brand->palette_error);
    }

    #[Test]
    public function decode_failure_sets_palette_error_to_extraction_empty(): void
    {
        // Non-image body: GD's imagecreatefromstring returns false,
        // LogoPaletteExtractor::extract returns null → BrandExtractor
        // labels it palette_extraction_empty (we bytes decoded but
        // no colors came out).
        Http::fake([
            'cdn.example.com/*' => Http::response('not an image', 200),
        ]);
        $extractor = new BrandExtractor(paletteExtractor: new LogoPaletteExtractor);
        $uploader = new FakeAssetUploader;
        $html = '<html><meta property="og:image" content="https://cdn.example.com/logo.png"></html>';
        $brand = $extractor->extract($html, 'org-1', $uploader);

        $this->assertSame('palette_extraction_empty', $brand->palette_error);
    }

    #[Test]
    public function no_logo_url_leaves_palette_error_null(): void
    {
        // Flag path — no logo was captured at all. That's a legitimate
        // absence, NOT a measurement failure. palette_error stays null;
        // SiteSettingsEmitter treats null as 'no_logo_measured' so a
        // reviewer can tell "measurement failed" apart from "nothing
        // to measure".
        $extractor = new BrandExtractor(paletteExtractor: new LogoPaletteExtractor);
        $uploader = new FakeAssetUploader;
        $brand = $extractor->extract('<html>no logos here</html>', 'org-1', $uploader);

        $this->assertSame('flag', $brand->logo_source);
        $this->assertNull($brand->palette_error);
    }
}

final class FakeAssetUploader implements AssetUploader
{
    public function putFromUrl(string $sourceUrl, string $orgId, string $kind): AssetRef
    {
        return new AssetRef(
            s3_key: 's3://engine-bucket/orgs/'.$orgId.'/'.$kind.'/fake.bin',
            mime_type: 'image/png',
            source_url: $sourceUrl,
        );
    }

    public function putContent(string $content, string $mimeType, string $orgId, string $kind, string $name): AssetRef
    {
        return new AssetRef(
            s3_key: 's3://engine-bucket/orgs/'.$orgId.'/'.$kind.'/'.$name,
            mime_type: $mimeType,
        );
    }
}
