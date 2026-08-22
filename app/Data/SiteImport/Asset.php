<?php

declare(strict_types=1);

namespace App\Data\SiteImport;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

// One declared asset in the assets[] ledger. Every `tl-asset:<ref>`
// token that appears in props MUST have a matching entry here, and
// every declared asset SHOULD be referenced (unreferenced entries
// upload as orphans on ingest). Site Import Contract Part II
// "Assets".
//
// sourceUrl is the ORIGINAL, absolute, publicly-fetchable third-
// party URL — NOT our S3 key. TeamLinkt fetches server-side, stores
// against the org, registers in the asset library, and rewrites
// every tl-asset:<ref> occurrence to the stored URL. Emitting our
// s3:// keys here would be a broken hotlink; emitting an absolute
// third-party URL directly in a prop (without a token) makes the
// rebuilt site hotlink the site it is replacing.
//
// Accepted mimeTypes:
//   images:    image/jpeg, image/png, image/webp, image/gif
//   documents: application/pdf
// SVG is REJECTED (stored-XSS vector) — rasterise to PNG ≥ 512 px
// before declaring. Anything outside this list should be omitted
// with a warning diagnostic rather than declared and rejected.
final class Asset extends Data
{
    /**
     * @param  string  $ref  matches the tl-asset:<ref> token; `[a-z0-9-]{1,64}`; unique within the payload
     * @param  string  $sourceUrl  original CDN URL — MUST be absolute, publicly fetchable, no auth
     * @param  string  $filename  original filename; used in the asset library listing
     * @param  string  $mimeType  verified against what the URL serves — not trusted
     * @param  Optional|string  $alt  strongly encouraged when known
     * @param  Optional|string  $usage  hint: logo | favicon | hero | gallery | document | other
     */
    public function __construct(
        public string $ref,
        public string $sourceUrl,
        public string $filename,
        public string $mimeType,
        public Optional|string $alt = new Optional,
        public Optional|string $usage = new Optional,
    ) {}
}
