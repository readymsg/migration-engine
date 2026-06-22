<?php

declare(strict_types=1);

namespace Tests\Support\Extract;

use App\Data\AssetRef;
use App\Services\Extract\AssetUploader;

// Returns deterministic `s3://fake/...` references without any I/O. The
// test asserts that downstream code only ever sees these refs — never
// raw bytes or live URLs.
final class FakeAssetUploader implements AssetUploader
{
    /** @var array<int, array{kind: string, key: string, source_url: ?string, bytes: int}> */
    public array $uploads = [];

    public function putFromUrl(string $sourceUrl, string $orgId, string $kind): AssetRef
    {
        $key = sprintf('s3://fake/%s/%s/%s%s', $orgId, $kind, sha1($sourceUrl), $this->extOf($sourceUrl));
        $this->uploads[] = ['kind' => $kind, 'key' => $key, 'source_url' => $sourceUrl, 'bytes' => 0];

        return new AssetRef(
            s3_key: $key,
            mime_type: $this->mimeOf($sourceUrl),
            source_url: $sourceUrl,
            bytes: 0,
        );
    }

    public function putContent(string $content, string $mimeType, string $orgId, string $kind, string $name): AssetRef
    {
        $key = sprintf('s3://fake/%s/%s/%s', $orgId, $kind, $name);
        $bytes = strlen($content);
        $this->uploads[] = ['kind' => $kind, 'key' => $key, 'source_url' => null, 'bytes' => $bytes];

        return new AssetRef(
            s3_key: $key,
            mime_type: $mimeType,
            source_url: null,
            bytes: $bytes,
        );
    }

    private function extOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return '';
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return $ext !== '' ? '.'.$ext : '';
    }

    private function mimeOf(string $url): string
    {
        return match (strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };
    }
}
