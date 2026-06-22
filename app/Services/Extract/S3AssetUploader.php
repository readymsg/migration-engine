<?php

declare(strict_types=1);

namespace App\Services\Extract;

use App\Data\AssetRef;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// S3-backed uploader. Uses the disk configured in config/filesystems.php
// (default 's3'). Returns `s3://<key>` references — never URLs, never bytes.
final class S3AssetUploader implements AssetUploader
{
    public function __construct(
        private readonly string $disk = 's3',
    ) {}

    public function putFromUrl(string $sourceUrl, string $orgId, string $kind): AssetRef
    {
        $response = Http::throw()->get($sourceUrl);
        $body = (string) $response->body();
        $mimeType = $this->stringHeader($response->header('Content-Type')) ?? 'application/octet-stream';
        $name = (string) Str::ulid().$this->extOf($sourceUrl, $mimeType);

        $ref = $this->putContent($body, $mimeType, $orgId, $kind, $name);

        return new AssetRef(
            s3_key: $ref->s3_key,
            mime_type: $mimeType,
            source_url: $sourceUrl,
            bytes: $ref->bytes,
        );
    }

    public function putContent(string $content, string $mimeType, string $orgId, string $kind, string $name): AssetRef
    {
        $key = "orgs/{$orgId}/{$kind}/{$name}";
        Storage::disk($this->disk)->put($key, $content);

        return new AssetRef(
            s3_key: "s3://{$key}",
            mime_type: $mimeType,
            source_url: null,
            bytes: strlen($content),
        );
    }

    private function extOf(string $url, string $mimeType): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path)) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext !== '') {
                return '.'.$ext;
            }
        }

        return match (true) {
            str_starts_with($mimeType, 'image/png') => '.png',
            str_starts_with($mimeType, 'image/jpeg') => '.jpg',
            str_starts_with($mimeType, 'image/svg') => '.svg',
            str_starts_with($mimeType, 'image/x-icon'),
            str_starts_with($mimeType, 'image/vnd.microsoft.icon') => '.ico',
            str_starts_with($mimeType, 'application/json') => '.json',
            default => '',
        };
    }

    /**
     * @param  array<int, string>|string|null  $header
     */
    private function stringHeader(array|string|null $header): ?string
    {
        if (is_string($header)) {
            return $header;
        }
        if (is_array($header) && isset($header[0]) && is_string($header[0])) {
            return $header[0];
        }

        return null;
    }
}
