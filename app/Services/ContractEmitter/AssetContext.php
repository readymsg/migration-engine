<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\AssetRef;
use Spatie\LaravelData\DataCollection;

// Resolves URL strings the emitter encounters (s3:// keys, live
// http(s) URLs, /preview-assets paths) back to the ORIGINAL third-
// party source URL — the value that goes into assets[].sourceUrl.
//
// Two layers of lookup:
//   1. If the URL is `s3://…` — resolve via Manifest.asset_refs's
//      `s3_key → source_url` map.
//   2. If the URL is already `http(s)://…` — pass through as its
//      own source URL (was never rehosted, likely a live CDN URL
//      the emitter can declare directly).
//
// Anything else (empty, malformed, `/preview-assets/*` — the latter
// is a preview-only concern that shouldn't reach the emitter) yields
// null, and the caller emits a diagnostic instead of a token.
final class AssetContext
{
    /** @var array<string, AssetRef> keyed by s3_key */
    private array $bySkey = [];

    /**
     * @param  DataCollection<int, AssetRef>  $assetRefs
     */
    public function __construct(DataCollection $assetRefs)
    {
        foreach ($assetRefs as $ref) {
            /** @var AssetRef $ref */
            $this->bySkey[$ref->s3_key] = $ref;
        }
    }

    /**
     * @return array{sourceUrl: string, mimeType: string, filename: string}|null
     */
    public function resolve(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // Case 1: our own s3:// key from AssetUrlRewriter's output.
        if (str_starts_with($url, 's3://')) {
            $ref = $this->bySkey[$url] ?? null;
            if ($ref === null || $ref->source_url === null || $ref->source_url === '') {
                return null;
            }

            $path = parse_url($ref->source_url, PHP_URL_PATH);

            return [
                'sourceUrl' => $ref->source_url,
                'mimeType' => $ref->mime_type,
                'filename' => is_string($path) ? (basename($path) ?: 'file') : 'file',
            ];
        }

        // Case 2: already an http(s) URL — pass through. We don't
        // know the mime type authoritatively; infer from extension.
        if (preg_match('#^https?://#i', $url)) {
            $mime = self::guessMime($url);
            if ($mime === null) {
                return null;
            }
            $path = parse_url($url, PHP_URL_PATH);
            $filename = is_string($path) ? (basename($path) ?: 'file') : 'file';

            return [
                'sourceUrl' => $url,
                'mimeType' => $mime,
                'filename' => $filename,
            ];
        }

        // Everything else (empty, /preview-assets, relative) — unresolved.
        return null;
    }

    private static function guessMime(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => null,
        };
    }
}
