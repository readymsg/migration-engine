<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One FilledPage that the deterministic assembler could not turn into a
// PuckOutput — typically because every block on the page was dropped
// during the validate→coerce→re-validate pass and emitting an empty
// PuckOutput would render as a blank page. Surfaced explicitly so SCORE
// & LOG can flag the conversion; never silently absent, never a blank
// stub PuckOutput.
//
// BlockFillFailures from upstream also pass through as AssemblyFailures
// (reason prefixed 'block-fill-failure:') so the conversion log sees
// every page once across the IR-pass → block-fill → assembler chain.
final class AssemblyFailure extends Data
{
    public function __construct(
        public string $page_slug,
        public string $page_title,
        public ?int $page_node_id,
        public string $reason,
    ) {}
}
