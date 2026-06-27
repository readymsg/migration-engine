<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One recorded coercion on a single block during assembly. ONLY
// substitutions and drops produce an issue — silent normalizations do
// not. Surfaced on AssemblyResult.block_issues_by_slug so SCORE & LOG
// can downgrade a conversion's structural confidence when blocks are
// substituted or dropped, and so a reviewer can spot-check what the
// assembler did before publish.
//
// `path` is null for top-level block issues (the block itself was
// dropped or had a top-level prop substituted). For issues inside
// nested fields (Columns.columns[i].children[j], Hero.cta, ButtonGroup
// .buttons[k]) `path` points at where in the block the coercion
// applied, e.g. 'props.columns[0].children[2]' for a dropped nested
// child. `component_type` is the IMMEDIATE offender's type — for a
// dropped child Card inside a Columns, component_type='Card' and the
// path points into the parent Columns at the position the Card lived.
final class AssemblyBlockIssue extends Data
{
    public function __construct(
        public int $block_index,
        public string $component_type,
        public AssemblyCoercion $coercion,
        public string $reason,
        public ?string $path = null,
    ) {}
}
