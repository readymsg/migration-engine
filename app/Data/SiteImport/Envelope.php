<?php

declare(strict_types=1);

namespace App\Data\SiteImport;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// TeamLinkt Website Builder Site Import Contract — top-level payload.
// See docs/site-import-contract 2.md (the v2 contract prose).
//
// The complete specification for producing TeamLinkt Website Builder
// content from outside their codebase. Two producers of this format:
//   1. This engine — the third-party scrape → normalize → translate
//      pipeline that this file is part of.
//   2. TeamLinkt's own internal legacy V1 migration job (out of scope
//      for us, but emits the SAME contract to the same ingest —
//      which is why this format is versioned).
//
// v2 removed the `diagnostics[]` field from the envelope entirely
// (the whole $def is gone; `additionalProperties: false` now rejects
// it at the envelope root). All diagnostic entries now live on
// App\Data\DiagnosticsSidecar, persisted alongside this envelope but
// NOT shipped inside it.
//
// Additive-only guarantee (Contract Part VI): a payload valid at
// version N stays valid at N+1. Expect new blocks, new props (with
// defaults), new enum values, new templates, new page layouts. Do
// NOT expect prop renames, prop deletions, or enum-value removals —
// those would orphan content in stored puck_data, and the contract
// commits to never doing them (enforced by a pre-commit guard on
// the TeamLinkt side).
final class Envelope extends Data
{
    // Bumped in lockstep with the site-import-schema.json we map
    // against. Contract v2 kept `schemaVersion: 1` — the wire format
    // is additive-only, so no bump — but the schema file itself was
    // regenerated (asset reshape + diagnostics removal). Track the
    // exact schema bytes via ContractSchema::sha256() (stamped into
    // DiagnosticsSidecar on every emission).
    public const SCHEMA_VERSION = 1;

    /**
     * @param  int  $schemaVersion  echo self::SCHEMA_VERSION; ingest rejects a mismatch before any content lands
     * @param  DataCollection<int, Page>  $pages  MUST contain at least one page with slug=""
     * @param  DataCollection<int, Asset>  $assets  every tl-asset:<ref> token in props MUST have a matching entry
     */
    public function __construct(
        public int $schemaVersion,
        public Source $source,
        public SiteSettings $site,
        #[DataCollectionOf(Page::class)]
        public DataCollection $pages,
        #[DataCollectionOf(Asset::class)]
        public DataCollection $assets,
    ) {}

    // Convenience factory for an empty-shell envelope. Used by tests
    // that verify the SHAPE (all five keys present, schemaVersion=1)
    // rather than content; the emitter never actually ships this
    // shape because pages[] must contain at least one page with
    // slug="".
    public static function emptyShell(Source $source): self
    {
        return new self(
            schemaVersion: self::SCHEMA_VERSION,
            source: $source,
            site: new SiteSettings,
            pages: new DataCollection(Page::class, []),
            assets: new DataCollection(Asset::class, []),
        );
    }
}
