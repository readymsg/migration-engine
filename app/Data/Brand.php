<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class Brand extends Data
{
    /**
     * @param  array<string, string>  $palette  free-form color tokens (e.g. primary/secondary/accent → "#0033FF")
     */
    public function __construct(
        // Tracks which step of the fallback ladder produced the logo:
        // 'header' | 'og_image' | 'favicon' | 'flag'.
        // TODO: enum once the extractor lands.
        public string $logo_source,
        public ?string $logo_asset_ref = null,
        public array $palette = [],
        public ?string $voice_hint = null,
    ) {}
}
