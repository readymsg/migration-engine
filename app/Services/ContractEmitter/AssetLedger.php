<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Asset;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;

// Accumulator for tl-asset:<ref> declarations across the whole
// emission. This is the inversion of our old AssetUrlRewriter:
//
//   OLD PATH: rewrite prop URLs to our own s3:// keys, host the
//             assets ourselves, serve via /preview-assets.
//   NEW PATH: emit tl-asset:<ref> tokens in props + declare each
//             asset's ORIGINAL third-party sourceUrl in assets[].
//             TeamLinkt fetches server-side and rewrites tokens.
//             Our S3 keys never appear in the payload.
//
// This class is the single place the emitter accumulates assets.
// Contract Part II "Assets" rules enforced here:
//   - Every token must have a matching assets[] entry (achieved
//     by construction — register() is the only way to mint a
//     token).
//   - Every declared asset should be referenced. Not enforced here
//     — the envelope-level validator (Slice 9) will spot orphans.
//   - Deduplicate by sourceUrl (one ref per distinct source, reused
//     across as many props as needed).
//   - SVG rejected (stored-XSS vector). Non-image/PDF rejected.
//     Callers get a RegistrationResult that says whether the asset
//     was accepted; a rejection becomes a diagnostic upstream, not
//     a hard failure.
//
// Ref grammar: `<usage>-<12 hex chars of sha1(sourceUrl)>`. Matches
// the contract's `[a-z0-9-]{1,64}` requirement and is deterministic
// (same source URL always produces the same ref — fixtures replay
// reproducibly). Usage defaults to `asset` if not provided.
final class AssetLedger
{
    /**
     * Contract Part II "Accepted types" — the only mimes an
     * ingest will accept. Anything outside this list becomes a
     * diagnostic instead of an assets[] entry.
     *
     * @var array<int, string>
     */
    private const ACCEPTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
    ];

    /**
     * Mimes we explicitly reject with a specific rejection reason.
     * SVG is the load-bearing one — Contract Part II calls it out
     * as a stored-XSS vector; "rasterise to PNG ≥ 512 px on the
     * long edge" is the workaround, which is deferred to Slice 20.
     *
     * @var array<string, string>
     */
    private const EXPLICITLY_REJECTED_MIME_TYPES = [
        'image/svg+xml' => 'SVG rejected by contract (stored-XSS vector). Rasterise to PNG ≥ 512 px before declaring.',
    ];

    /** @var array<string, Asset> keyed by sourceUrl (dedup key) */
    private array $bySource = [];

    /** @var array<string, true> ref uniqueness check */
    private array $refs = [];

    public function register(
        string $sourceUrl,
        string $filename,
        string $mimeType,
        ?string $alt = null,
        ?string $usage = null,
    ): RegistrationResult {
        $sourceUrl = trim($sourceUrl);
        $mimeType = strtolower(trim($mimeType));

        if ($sourceUrl === '') {
            return RegistrationResult::rejected('empty sourceUrl');
        }

        // Contract Part II "Assets": absolute, publicly fetchable, no
        // auth. We can't verify fetchability from here, but we can
        // require an absolute http(s) URL — TeamLinkt fetches server-
        // side and a scheme-less URL wouldn't resolve there.
        if (! preg_match('#^https?://#i', $sourceUrl)) {
            return RegistrationResult::rejected(
                "sourceUrl must be absolute http(s); got `{$sourceUrl}`",
            );
        }

        // Explicit-reject list (SVG is the current entry).
        if (isset(self::EXPLICITLY_REJECTED_MIME_TYPES[$mimeType])) {
            return RegistrationResult::rejected(
                self::EXPLICITLY_REJECTED_MIME_TYPES[$mimeType],
            );
        }

        // Everything else must be in the accept list.
        if (! in_array($mimeType, self::ACCEPTED_MIME_TYPES, true)) {
            return RegistrationResult::rejected(
                "mimeType `{$mimeType}` not in the contract's accepted list (image/jpeg|png|webp|gif or application/pdf)",
            );
        }

        // Dedupe by sourceUrl.
        if (isset($this->bySource[$sourceUrl])) {
            return RegistrationResult::accepted($this->bySource[$sourceUrl]->ref);
        }

        $ref = $this->mintRef($sourceUrl, $usage);
        $this->refs[$ref] = true;
        $this->bySource[$sourceUrl] = new Asset(
            ref: $ref,
            sourceUrl: $sourceUrl,
            filename: $filename,
            mimeType: $mimeType,
            alt: $alt !== null && $alt !== '' ? $alt : new Optional,
            usage: $usage !== null && $usage !== '' ? $usage : new Optional,
        );

        return RegistrationResult::accepted($ref);
    }

    /**
     * Convenience: register and return the tl-asset:<ref> token
     * directly. Returns null when the asset was rejected — caller
     * decides whether to substitute or emit a diagnostic.
     */
    public function tokenFor(
        string $sourceUrl,
        string $filename,
        string $mimeType,
        ?string $alt = null,
        ?string $usage = null,
    ): ?string {
        $result = $this->register($sourceUrl, $filename, $mimeType, $alt, $usage);

        return $result->rejected ? null : "tl-asset:{$result->ref}";
    }

    /**
     * @return DataCollection<int, Asset> Assets in registration order.
     */
    public function all(): DataCollection
    {
        return new DataCollection(Asset::class, array_values($this->bySource));
    }

    public function count(): int
    {
        return count($this->bySource);
    }

    public function hasRef(string $ref): bool
    {
        return isset($this->refs[$ref]);
    }

    public function refForSource(string $sourceUrl): ?string
    {
        return $this->bySource[$sourceUrl]->ref ?? null;
    }

    private function mintRef(string $sourceUrl, ?string $usage): string
    {
        $prefix = $usage !== null && $usage !== '' ? $usage : 'asset';
        // Contract allows `[a-z0-9-]{1,64}`; normalise any non-
        // conformant usage hint to `asset` rather than emit an
        // invalid ref.
        if (! preg_match('/^[a-z0-9-]+$/', $prefix)) {
            $prefix = 'asset';
        }
        $hash = substr(sha1($sourceUrl), 0, 12);
        $candidate = "{$prefix}-{$hash}";
        // In practice sha1 collisions on 12 chars for our sizes are
        // effectively zero, but bump the suffix if we ever hit one.
        $i = 1;
        while (isset($this->refs[$candidate])) {
            $candidate = "{$prefix}-{$hash}-{$i}";
            $i++;
        }

        return $candidate;
    }
}
