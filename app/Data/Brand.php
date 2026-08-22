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
        // Original CDN URL of the logo, preserved alongside the
        // rehosted s3_key so downstream consumers (specifically the
        // throwaway preview asset resolver) can fall back to fetching
        // the source when the local rehost isn't on disk. Same shape
        // as AssetRef.source_url — nullable, informational, never
        // used by the landed draft.
        public ?string $logo_source_url = null,
        public array $palette = [],
        public ?string $voice_hint = null,
        // Why palette measurement wasn't available, when it wasn't.
        // Set by BrandExtractor::measurePalette. Null on success OR when
        // no palette measurement was attempted (no logo URL to measure).
        // Consumed by SiteSettingsEmitter to produce a LOUD fallback
        // diagnostic naming which palette source was used and why the
        // measured one was unavailable — Contract Part II calls
        // primaryColor/neutralColor the highest-value fields, so a
        // silent fallback here is the exact silent-loss surface we're
        // closing.
        //
        // Values used today:
        //   'no_palette_extractor'         — BrandExtractor was constructed
        //                                    without a LogoPaletteExtractor
        //                                    (legacy/test paths only after
        //                                    the AppServiceProvider binding).
        //   'logo_fetch_failed: <reason>'  — HTTP fetch of the logo bytes
        //                                    failed or returned non-2xx.
        //   'logo_body_empty'              — fetch succeeded but body was
        //                                    empty (zero-byte response).
        //   'palette_extraction_empty'     — bytes decoded but no colors
        //                                    could be extracted (all-
        //                                    transparent PNG etc.).
        public ?string $palette_error = null,
    ) {}
}
