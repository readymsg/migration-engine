<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class Manifest extends Data
{
    /**
     * Output of the INGEST stage. Structure + brand + content/asset refs.
     * Asset payloads are always S3 references — never binary.
     *
     * v1 scope cut: provisioning (teams/divisions/admins) is NOT extracted —
     * v1 focuses on the site rebuild only. The Provisioning DTO is kept as
     * scaffolding for a later phase; today this field is always null.
     *
     * Faithful-rebuild guarantee for content: every kind=page nav node with
     * a URL either produces a ContentRef OR a ContentExtractionFailure.
     * The counts tie out (no silent absences).
     *
     * SE-CDN asset re-hosting is a softer signal: per-asset failures don't
     * fail a page (the body is still captured), but the counts MUST be
     * visible — cdn_assets_found vs cdn_assets_rehosted — so a page that
     * silently lost half its images isn't invisible.
     *
     * @param  DataCollection<int, ContentRef>  $content_refs
     * @param  DataCollection<int, AssetRef>  $asset_refs
     * @param  DataCollection<int, ContentExtractionFailure>  $content_failures
     * @param  array<int, string>  $flags  free-form warnings surfaced by the extractor
     */
    public function __construct(
        public string $source_url,
        public string $org_id,
        public SiteStructure $structure,
        public ?Provisioning $provisioning,
        public Brand $brand,
        #[DataCollectionOf(ContentRef::class)]
        public DataCollection $content_refs,
        #[DataCollectionOf(AssetRef::class)]
        public DataCollection $asset_refs,
        public float $confidence,
        public array $flags = [],
        #[DataCollectionOf(ContentExtractionFailure::class)]
        public ?DataCollection $content_failures = null,
        public int $cdn_assets_found = 0,
        public int $cdn_assets_rehosted = 0,
    ) {}
}
