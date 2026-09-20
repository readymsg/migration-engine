<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Asset;
use App\Data\SiteImport\Diagnostic;
use App\Services\Assets\AssetPublisher;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;

// Accumulator for tl-asset:<ref> declarations across the whole
// emission. Contract v2 §9 "Asset hosting": WE host every asset on
// one allow-listed https origin. The ledger owns the AssetPublisher
// call — on register() it fetches (via disk-cached HTTP) → byte-
// sniffs the mime → measures dimensions → publishes to the host →
// declares the resulting Asset with both `url` (host-issued) AND
// `sourceUrl` (provenance-only).
//
// SILENT-LOSS POLICY: an asset that can't be published (fetch failed,
// SVG needs rasterisation, unknown mime) is NOT declared and is NOT
// silently forgotten either — the ledger records a `Diagnostic` in
// its own accumulator which the emitter drains into the sidecar.
//
// Ref grammar unchanged: `<usage>-<12 hex chars of sha1(sourceUrl)>`.
// Deterministic — same source URL always produces the same ref, so
// fixtures replay reproducibly. Usage defaults to `asset`.
final class AssetLedger
{
    /** @var array<string, Asset> keyed by sourceUrl (dedup key) */
    private array $bySource = [];

    /** @var array<string, true> ref uniqueness check */
    private array $refs = [];

    /** @var array<int, Diagnostic> skip diagnostics — drained by emitter into the sidecar */
    private array $skipDiagnostics = [];

    private readonly AssetPublisher $publisher;

    // `$publisher` defaults to a fresh AssetPublisher backed by a
    // FakePublicAssetHost. That default is HERE (not on the ctor
    // signature) so unit tests instantiating `new AssetLedger` from
    // hundreds of places keep working — they don't care about the
    // published URL, only about registration semantics. The live
    // emission path (ContractPayloadEmitter) constructs a ledger with
    // the container-bound AssetPublisher so production INGEST uses
    // whichever PublicAssetHost is bound (FakePublicAssetHost for
    // fixture emission today; SpacesPublicAssetHost when it lands).
    public function __construct(?AssetPublisher $publisher = null)
    {
        $this->publisher = $publisher ?? \App\Services\Assets\SyntheticAssetPublisher::default();
    }

    public function register(
        string $sourceUrl,
        string $filename,
        string $mimeType,
        ?string $alt = null,
        ?string $usage = null,
    ): RegistrationResult {
        $sourceUrl = trim($sourceUrl);

        if ($sourceUrl === '') {
            return RegistrationResult::rejected('empty sourceUrl');
        }

        // Contract v2 §7 "Assets": absolute, publicly fetchable, no
        // auth. Also required for AssetPublisher to fetch bytes.
        if (! preg_match('#^https?://#i', $sourceUrl)) {
            return RegistrationResult::rejected(
                "sourceUrl must be absolute http(s); got `{$sourceUrl}`",
            );
        }

        // Dedupe by sourceUrl — one publish per distinct source.
        if (isset($this->bySource[$sourceUrl])) {
            return RegistrationResult::accepted($this->bySource[$sourceUrl]->ref);
        }

        // Publish through the host. Fetch failures, SVG, and non-
        // accepted mimes come back as `skip_reason` — surface as a
        // sidecar diagnostic so the reviewer sees the exact drop.
        $publishResult = $this->publisher->publishFromUrl($sourceUrl, $filename);
        if (! $publishResult->isOk()) {
            $reason = $publishResult->skip_reason ?? 'unknown publish failure';
            $this->skipDiagnostics[] = new Diagnostic(
                severity: 'warning',
                code: self::codeForSkip($reason),
                message: "Asset not declared: {$reason}",
                sourceUrl: $sourceUrl,
            );

            return RegistrationResult::rejected($reason);
        }
        $published = $publishResult->published;
        assert($published !== null);

        $ref = $this->mintRef($sourceUrl, $usage);
        $this->refs[$ref] = true;

        $this->bySource[$sourceUrl] = new Asset(
            ref: $ref,
            url: $published->url,
            filename: $filename,
            mimeType: $published->mimeType,
            sourceUrl: $sourceUrl,
            sha256: $published->sha256,
            byteSize: $published->byteSize,
            width: $published->width ?? new Optional,
            height: $published->height ?? new Optional,
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

    /**
     * Drain the skip diagnostics accumulated during publish attempts.
     * Called once by the emitter after all mapping is done; folded
     * into the sidecar's diagnostics list.
     *
     * @return array<int, Diagnostic>
     */
    public function skipDiagnostics(): array
    {
        return $this->skipDiagnostics;
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

    private static function codeForSkip(string $reason): string
    {
        if (str_starts_with($reason, 'svg_rasterizer_missing')) {
            return 'svg_rasterizer_missing';
        }
        if (str_starts_with($reason, 'mime_not_accepted')) {
            return 'asset_mime_rejected';
        }
        if (str_starts_with($reason, 'fetch_failed')) {
            return 'asset_fetch_failed';
        }
        if (str_starts_with($reason, 'empty body')) {
            return 'asset_body_empty';
        }
        if (str_starts_with($reason, 'mime_sniff_failed')) {
            return 'asset_mime_sniff_failed';
        }
        if (str_starts_with($reason, 'host_put_threw')) {
            return 'asset_host_put_threw';
        }

        return 'asset_publish_skipped';
    }
}
