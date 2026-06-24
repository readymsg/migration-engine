<?php

declare(strict_types=1);

namespace Tests\Unit\Extract;

use App\Services\Extract\S3AssetUploader;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

// The contract that this slice locks in: a write that didn't land must
// never report success. Storage::disk()->put() returns a bool, and the
// s3 disk is configured with 'throw' => false — so a failed write (no
// bucket, no perms, etc.) silently returns false. Without this check,
// upstream sees a healthy-looking AssetRef pointing at a key that has
// nothing behind it. The previous live tbirdhoops probe was bitten by
// exactly that: 9 phantom-success ContentRefs, S3 readback all null.
final class S3AssetUploaderTest extends TestCase
{
    #[Test]
    public function put_content_throws_when_disk_put_returns_false(): void
    {
        $this->registerDisk('uploader-failing', $this->diskReturning(false));

        $uploader = new S3AssetUploader(disk: 'uploader-failing');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/returned false/');
        $uploader->putContent('hello', 'text/plain', 'org-1', 'scrapes', 'home.json');
    }

    #[Test]
    public function put_content_returns_asset_ref_when_disk_put_succeeds(): void
    {
        $this->registerDisk('uploader-working', $this->diskReturning(true));

        $uploader = new S3AssetUploader(disk: 'uploader-working');

        $ref = $uploader->putContent('hello', 'text/plain', 'org-1', 'scrapes', 'home.json');

        $this->assertSame('s3://orgs/org-1/scrapes/home.json', $ref->s3_key);
        $this->assertSame('text/plain', $ref->mime_type);
        $this->assertNull($ref->source_url);
        $this->assertSame(5, $ref->bytes);
    }

    #[Test]
    public function put_from_url_propagates_disk_failure_after_successful_http_fetch(): void
    {
        // Even when the source URL fetches cleanly, a downstream disk put
        // failure must surface — never become a phantom AssetRef.
        Http::fake([
            'cdn.example.com/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
        ]);
        $this->registerDisk('uploader-failing-fromurl', $this->diskReturning(false));

        $uploader = new S3AssetUploader(disk: 'uploader-failing-fromurl');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/returned false/');
        $uploader->putFromUrl('https://cdn.example.com/logo.png', 'org-1', 'logos');
    }

    private function registerDisk(string $name, Filesystem $disk): void
    {
        /** @var FilesystemManager $manager */
        $manager = $this->app->make('filesystem');
        $manager->set($name, $disk);
    }

    private function diskReturning(bool $putResult): Filesystem
    {
        $disk = $this->createMock(Filesystem::class);
        $disk->method('put')->willReturn($putResult);

        return $disk;
    }
}
