<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One SourceElement after the coverage reconciler has classified it
// against a page's rebuilt Puck content + scrub sidecar.
//
// disposition is a string in {captured, superseded, dropped} — the same
// three-state axis as AssignmentDisposition (for blocks) but at the
// finer SOURCE ELEMENT level. Kept as a string to avoid coupling the
// element-level disposition to the block-level enum (they answer
// different questions and one may grow states the other doesn't need).
//
// evidence:
//   - captured  → the normalized block text/URL substring that matched
//   - superseded → 'platform_block:<type>' or 'scrub:<kind>' or
//                  'scrub_summary:<matched substring>'
//   - dropped   → '' (no evidence — that's the point)
final class ReconciledElement extends Data
{
    public function __construct(
        public SourceElement $source,
        public string $disposition,
        public string $reason,
        public string $evidence = '',
    ) {}
}
