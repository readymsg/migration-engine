<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Diagnostic;
use App\Data\SiteImport\SiteSettings;

// SiteSettingsEmitter's return DTO. Carries the resolved SiteSettings
// PLUS per-slot palette-source diagnostics. The diagnostics are what
// make the measured→LLM fallback LOUD instead of silent — Contract
// Part II calls primaryColor/neutralColor the highest-value fields
// in the whole payload, so a silent fallback on those slots is the
// exact silent-loss surface we're closing.
final class SiteSettingsEmitResult
{
    /**
     * @param  array<int, Diagnostic>  $diagnostics
     */
    public function __construct(
        public readonly SiteSettings $settings,
        public readonly array $diagnostics,
    ) {}
}
