<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Contracts\PublicAssetHost;
use App\Data\PublishedAsset;

// Fixture + test PublicAssetHost implementation. Content-addressed
// by sha256, returns a deterministic https URL, keeps bytes in
// memory so BrandExtractor's palette measurement can read them back
// without a second HTTP fetch.
//
// URL shape: `https://assets.example.test/<sha256>.<ext>`
//   - The .test TLD is reserved by RFC 2606 for testing — nobody
//     can accidentally hit a real server.
//   - <ext> comes from the sniffed mimeType so a URL is at least
//     visually recognisable (a .png URL is a PNG).
//
// The FIRST test to publish bytes registers them; subsequent
// publishes with the SAME bytes return the same URL. The `bytes`
// map is process-local — reset between tests via a fresh service
// container binding, or explicitly via `reset()`.
final class FakePublicAssetHost implements PublicAssetHost
{
    private const ORIGIN = 'https://assets.example.test';

    /** @var array<string, string> url → bytes (content-addressed) */
    private array $bytesByUrl = [];

    public function put(string $bytes, string $filename, string $mimeType): PublishedAsset
    {
        $sha = hash('sha256', $bytes);
        $ext = self::extFor($mimeType, $filename);
        $url = self::ORIGIN.'/'.$sha.($ext !== '' ? '.'.$ext : '');
        $this->bytesByUrl[$url] = $bytes;

        [$w, $h] = self::measureDimensions($bytes);

        return new PublishedAsset(
            url: $url,
            sha256: $sha,
            byteSize: strlen($bytes),
            width: $w,
            height: $h,
            mimeType: $mimeType,
            filename: $filename,
        );
    }

    public function get(string $url): ?string
    {
        return $this->bytesByUrl[$url] ?? null;
    }

    public function origin(): string
    {
        return self::ORIGIN;
    }

    /**
     * Test helper — wipe the in-memory store.
     */
    public function reset(): void
    {
        $this->bytesByUrl = [];
    }

    /**
     * mimeType → file extension for URL readability. Falls back to
     * the original filename's extension when unknown, then to empty
     * string.
     */
    private static function extFor(string $mimeType, string $filename): string
    {
        $mime = strtolower(trim($mimeType));
        $known = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            'image/svg+xml' => 'svg',
            default => null,
        };
        if ($known !== null) {
            return $known;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $ext !== '' ? $ext : '';
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private static function measureDimensions(string $bytes): array
    {
        if ($bytes === '') {
            return [null, null];
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return [null, null];
        }

        return [$info[0], $info[1]];
    }
}
