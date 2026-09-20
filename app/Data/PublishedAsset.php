<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// Return of `PublicAssetHost::put()`. Carries every field the v2
// contract puts on assets[] EXCEPT `sourceUrl` (which is provenance,
// tracked by AssetLedger separately) and `alt`/`usage` (caller-
// supplied hints, also passed to AssetLedger separately).
//
// The four measurement fields (sha256, byteSize, width, height)
// are computed by the AssetPublisher from the actual bytes it
// hands to the host. `mimeType` is byte-sniffed via finfo, NOT
// trusted from any HTTP header (v2 §9: "verified against what the
// URL actually serves, not trusted").
//
// `width` and `height` are null when the asset isn't a bitmap
// image (PDFs, or an unreadable image). The host does NOT reject on
// missing dimensions — it just declares them absent. AssetLedger
// makes the final decision on whether to emit an assets[] entry.
final class PublishedAsset extends Data
{
    public function __construct(
        public string $url,
        public string $sha256,
        public int $byteSize,
        public ?int $width,
        public ?int $height,
        public string $mimeType,
    ) {}
}
