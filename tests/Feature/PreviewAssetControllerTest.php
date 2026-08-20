<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// PreviewAssetController — the throwaway preview-only asset resolver.
//
// Pins the provenance contract: every response carries a
// X-Preview-Asset-Source header, and a fallback fetch is never
// silent. If any of these ever regress, a reviewer can no longer tell
// "self-hosted and working" from "still pulling from SportsEngine"
// which is the exact failure the header exists to prevent.
final class PreviewAssetControllerTest extends TestCase
{
    #[Test]
    public function local_hit_serves_disk_bytes_with_source_local_header(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('orgs/ngin-x/content_assets/hero.jpg', 'JPEG-BYTES');

        $res = $this->get('/preview-assets?p='.urlencode('s3://orgs/ngin-x/content_assets/hero.jpg'));

        $res->assertOk();
        $res->assertHeader('X-Preview-Asset-Source', 'local');
        $this->assertSame('JPEG-BYTES', $res->getContent());
    }

    #[Test]
    public function missing_local_falls_back_to_cdn_with_source_fallback_header(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn4.sportngin.com/*' => Http::response('CDN-BYTES', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $s3 = 's3://orgs/ngin-x/content_assets/missing.jpg';
        $src = 'https://cdn4.sportngin.com/attachments/photo/64f2/LTYB.jpg';
        $res = $this->get('/preview-assets?p='.urlencode($s3).'&f='.urlencode($src));

        $res->assertOk();
        $res->assertHeader('X-Preview-Asset-Source', 'fallback:cdn4.sportngin.com');
        $this->assertSame('CDN-BYTES', $res->getContent());
    }

    #[Test]
    public function missing_local_without_fallback_url_returns_404_with_source_missing(): void
    {
        Storage::fake('local');
        $s3 = 's3://orgs/ngin-x/content_assets/absent.jpg';
        $res = $this->get('/preview-assets?p='.urlencode($s3));
        $res->assertStatus(404);
        $res->assertHeader('X-Preview-Asset-Source', 'missing');
    }

    #[Test]
    public function refuses_fallback_to_non_allowlisted_host(): void
    {
        // Belt-and-braces SSRF guard — even if the client tries to
        // route the fallback through an arbitrary host, only SE-CDN
        // hosts are accepted. An attacker crafting a fallback param
        // pointing at internal infra gets 404 with source=missing.
        Storage::fake('local');
        Http::preventStrayRequests();

        $s3 = 's3://orgs/ngin-x/content_assets/x.jpg';
        $malicious = 'http://169.254.169.254/latest/meta-data/'; // AWS IMDS
        $res = $this->get('/preview-assets?p='.urlencode($s3).'&f='.urlencode($malicious));

        $res->assertStatus(404);
        $res->assertHeader('X-Preview-Asset-Source', 'missing');
    }

    #[Test]
    public function rejects_path_traversal_in_p_param(): void
    {
        Storage::fake('local');
        $traversal = 's3://../../../etc/passwd';
        $res = $this->get('/preview-assets?p='.urlencode($traversal));
        $res->assertStatus(404);
        $res->assertHeader('X-Preview-Asset-Source', 'missing');
    }

    #[Test]
    public function missing_p_query_param_returns_400(): void
    {
        $res = $this->get('/preview-assets');
        $res->assertStatus(400);
    }
}
