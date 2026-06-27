<?php

declare(strict_types=1);

namespace App\Services\Plan;

// Result of one SePlatformContentDetector::detect() call. Carries the
// boolean verdict and the raw signal values so PLAN can build a loud,
// specific ledger reason — a reviewer must be able to read exactly WHY
// the detector parked a page (and decide to promote it back if the call
// was wrong).
//
// Not a Data DTO because it never crosses a contract boundary — it's an
// in-process carrier from the detector to RootNavPlanner.
final class SePlatformContentVerdict
{
    /**
     * @param  array<int, string>  $vocab_phrases_matched  distinct SE-platform vocabulary phrases found (lowercase), in detector order
     */
    public function __construct(
        public readonly bool $is_se_platform,
        public readonly int $total_outbound_links,
        public readonly int $se_platform_links,
        public readonly float $ratio,
        public readonly array $vocab_phrases_matched,
    ) {}
}
