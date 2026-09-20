<?php

declare(strict_types=1);

namespace App\Data\SiteImport;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

// One declared asset in the assets[] ledger. Every `tl-asset:<ref>`
// token that appears in props MUST have a matching entry here, and
// every declared asset SHOULD be referenced (unreferenced entries
// upload as orphans on ingest). Contract v2 §7 + §9 "Assets" + "Asset
// hosting".
//
// v2 reshape (was: {ref, sourceUrl, filename, mimeType, alt?, usage?}):
//   `url` is now REQUIRED — the publicly-fetchable https URL WE host
//   the file at (single allow-listed origin per §9 "Asset hosting").
//   `sourceUrl` kept its name but flipped role to provenance-only —
//   what makes a wrong/broken asset traceable back to its origin. It's
//   optional now; never fetched by TeamLinkt.
//   Four new optional fields — `sha256`, `byteSize`, `width`, `height`
//   — let the receiver verify what it fetched and dedupe by hash.
//
// Accepted mimeTypes (unchanged from v1):
//   images:    image/jpeg, image/png, image/webp, image/gif
//   documents: application/pdf
// SVG remains REJECTED (stored-XSS vector) — rasterise to PNG ≥ 512
// px before declaring. Rasterisation is a Known Gap in this slice.
final class Asset extends Data
{
    /**
     * @param  string  $ref  matches the tl-asset:<ref> token; `[a-z0-9-]{1,64}`; unique within the payload
     * @param  string  $url  publicly-fetchable https URL on the agreed asset host — v2 REQUIRED
     * @param  string  $filename  original filename; used in the asset library listing
     * @param  string  $mimeType  byte-sniffed via finfo — not trusted from a Content-Type header (v2 §9)
     * @param  Optional|string  $sourceUrl  original CDN URL — provenance only, NEVER fetched by TeamLinkt (v2)
     * @param  Optional|string  $sha256  lowercase hex sha256 of the bytes at `url` — enables receiver dedup + verification
     * @param  Optional|int  $byteSize  byte size of the file at `url` — v2 §7 caps assets at 10 MB
     * @param  Optional|int  $width  intrinsic pixel width (bitmap images only)
     * @param  Optional|int  $height  intrinsic pixel height (bitmap images only)
     * @param  Optional|string  $alt  strongly encouraged when known
     * @param  Optional|string  $usage  hint: logo | favicon | hero | gallery | document | other
     */
    public function __construct(
        public string $ref,
        public string $url,
        public string $filename,
        public string $mimeType,
        public Optional|string $sourceUrl = new Optional,
        public Optional|string $sha256 = new Optional,
        public Optional|int $byteSize = new Optional,
        public Optional|int $width = new Optional,
        public Optional|int $height = new Optional,
        public Optional|string $alt = new Optional,
        public Optional|string $usage = new Optional,
    ) {}
}
