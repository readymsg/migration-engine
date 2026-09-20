<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\PublishedAsset;

// Contract v2 §9 "Asset hosting" — we host every asset we declare on
// ONE agreed https origin. `PublicAssetHost` is the seam that
// upstream code (AssetPublisher, BrandExtractor) uses to publish
// bytes and receive the publicly-fetchable https URL.
//
// Two implementations under the interface:
//
//   FakePublicAssetHost  — in-memory, deterministic, keyed by sha256.
//                          Returns https://assets.example.test/<sha>.<ext>.
//                          Used for fixture emission + tests.
//
//   SpacesPublicAssetHost (not built in this slice) — real S3+CloudFront
//                          against a per-environment bucket, allow-listed
//                          origin.
//
// The interface is deliberately narrow — no delete, no metadata
// re-read. Assets are content-addressed by sha256 (same bytes always
// produce the same URL), so `put` is idempotent by construction.
// A caller wanting "have we published these bytes before" hashes the
// bytes and reads its own cache; the host isn't a database.
interface PublicAssetHost
{
    /**
     * Publish these bytes and return their publicly-fetchable https
     * URL + all the measurement fields v2 requires on assets[].
     * Idempotent: same bytes ⇒ same URL.
     *
     * `$filename` is used only to derive the URL's extension (for
     * readability); the on-host name is content-addressed. `$mimeType`
     * is what the host declares — callers SHOULD have already
     * byte-sniffed it via finfo.
     */
    public function put(string $bytes, string $filename, string $mimeType): PublishedAsset;

    /**
     * Read bytes back from a URL this host issued. Returns null if
     * the URL is unknown to this host (or the underlying storage is
     * gone). Used by BrandExtractor to feed rehosted PNG bytes to
     * LogoPaletteExtractor without a second HTTP fetch (v2 §9's SSRF
     * concern — "the whole SSRF surface"). Not for hot-path serving.
     */
    public function get(string $url): ?string;

    /**
     * The single allow-listed origin, e.g. `https://assets.example.test`
     * (fake) or `https://assets.migration.teamlinkt.com` (prod). Used
     * for defensive checks (a URL that doesn't start with this origin
     * didn't come from us).
     */
    public function origin(): string;
}
