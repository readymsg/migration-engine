<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\ContentRef;
use Illuminate\Support\Facades\Storage;
use JsonException;

// Reads a captured page body back from the configured scrapes disk so the
// IR pass can design from the REAL content the extractor captured. Before
// this seam landed, the IR pass designed from nav labels alone — that
// produced generic architecture that didn't reflect what's on the page.
//
// scrape_ref shape: the extractor writes scrapes via S3AssetUploader, which
// returns a LOGICAL "s3://orgs/{org}/scrapes/{x}.json" key REGARDLESS of
// the underlying disk (it's a stable handle, not a real S3 URL). Strip the
// "s3://" prefix; what's left is the disk-relative key readable via
// Storage::disk(...).
//
// Returns null on miss / malformed JSON / empty markdown. The caller turns
// a null into an explicit IrPassFailure so a body we couldn't read NEVER
// masquerades as a successfully-designed page — faithful-rebuild guarantee
// at the IR-pass layer mirrors INGEST's: every Keep content page resolves
// to EITHER a designed Ir OR a flagged failure, never silent absence.
final class ContentLoader
{
    public function __construct(
        private readonly string $disk = 's3',
    ) {}

    public function load(ContentRef $ref): ?LoadedContent
    {
        $key = $this->stripLogicalPrefix($ref->scrape_ref);
        if ($key === '') {
            return null;
        }

        $disk = Storage::disk($this->disk);
        if (! $disk->exists($key)) {
            return null;
        }

        $raw = $disk->get($key);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($decoded)) {
            return null;
        }

        $markdown = is_string($decoded['markdown'] ?? null) ? $decoded['markdown'] : '';
        if ($markdown === '') {
            // Empty body is functionally the same as no body for IR design —
            // surface it as a miss so the page is flagged, not "designed"
            // from nothing.
            return null;
        }

        /** @var array<int, string> $imageUrls */
        $imageUrls = [];
        $rawImages = $decoded['image_urls'] ?? [];
        if (is_array($rawImages)) {
            foreach ($rawImages as $url) {
                if (is_string($url) && $url !== '') {
                    $imageUrls[] = $url;
                }
            }
        }

        return new LoadedContent(
            markdown: $markdown,
            image_urls: $imageUrls,
        );
    }

    private function stripLogicalPrefix(string $key): string
    {
        if (str_starts_with($key, 's3://')) {
            return substr($key, 5);
        }

        return $key;
    }
}
