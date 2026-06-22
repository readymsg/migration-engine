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
     * @param  DataCollection<int, ContentRef>  $content_refs
     * @param  DataCollection<int, AssetRef>  $asset_refs
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
    ) {}
}
