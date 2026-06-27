<?php

declare(strict_types=1);

namespace App\Services\Generate;

// Internal validator → coercer handoff. Not surfaced on the public
// AssemblyResult — public surface is AssemblyBlockIssue, which is only
// produced for non-silent coercions. ValidationIssue describes
// EVERYTHING the validator found (including unknown-prop / wrong-type
// cases that the coercer will fix silently).
//
// `path` is dotted with explicit array indices, e.g.
// 'props.columns[0].children[2].props.title' — points at the field
// where the issue was detected. Empty path for an issue on the block
// itself (e.g. UnknownComponent).
final class ValidationIssue
{
    public function __construct(
        public readonly string $path,
        public readonly ValidationKind $kind,
        public readonly string $detail,
    ) {}
}
