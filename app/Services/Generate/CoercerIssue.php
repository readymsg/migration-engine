<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\AssemblyCoercion;

// Internal coercer → assembler handoff. The coercer doesn't know which
// top-level block index it's operating on (that's the Assembler's
// concern), so it emits CoercerIssue with relative paths. The Assembler
// converts each to a public AssemblyBlockIssue with block_index filled
// in before surfacing on AssemblyResult.block_issues_by_slug.
final class CoercerIssue
{
    public function __construct(
        public readonly string $component_type,
        public readonly AssemblyCoercion $coercion,
        public readonly string $reason,
        public readonly ?string $path = null,
    ) {}
}
