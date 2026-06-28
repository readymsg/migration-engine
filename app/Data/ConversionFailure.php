<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One page-level failure surfaced by draft-landing. Unified shape across
// every upstream failure type (IrPassFailure, BlockFillFailure,
// AssemblyFailure, PlatformRenderFailure) plus draft-landing-level
// failures (defensive slug-collision, unmatchable nav reconciliation,
// createDraftSite client errors). Carries the originating stage so SCORE
// & LOG can group/summarize without re-parsing reason-string prefixes.
//
// Same flat shape every other failure DTO uses — keeps the conversion-
// log layer's job mechanical.
final class ConversionFailure extends Data
{
    public function __construct(
        public string $page_slug,
        public string $page_title,
        public ?int $page_node_id,
        public ConversionStage $stage,
        public string $reason,
    ) {}
}
