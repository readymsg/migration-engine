<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\OrgType;
use App\Data\SiteImport\Block;
use App\Data\SiteImport\Diagnostic;

// Slice 15f: filter out contract Blocks whose type is gated to a
// subset that excludes the current orgType. Contract Part II
// "Org types" is explicit that a gated-out block for the wrong
// orgType is an ERROR — the block would not even appear in the
// org's palette on the receiving side, so silently emitting one
// would fail ingest validation.
//
// Extracted from ContractPayloadEmitter so gating logic can be
// unit-tested without spinning up the full emit pipeline.
final class OrgTypeGate
{
    public function __construct(
        private readonly ContractSchema $schema,
    ) {}

    /**
     * @param  array<int, Block>  $blocks
     * @return array{0: array<int, Block>, 1: array<int, Diagnostic>}
     */
    public function apply(array $blocks, OrgType $orgType, string $pageSlug): array
    {
        $kept = [];
        $diagnostics = [];
        foreach ($blocks as $block) {
            if ($this->schema->blockAllowsOrgType($block->type, $orgType->value)) {
                $kept[] = $block;

                continue;
            }
            $allowed = implode(', ', $this->schema->orgTypesFor($block->type));
            $diagnostics[] = new Diagnostic(
                severity: 'error',
                code: 'org_type_gate_dropped_block',
                message: sprintf(
                    'Detected scraped pattern that would emit `%s` on page `%s`, but this block is gated to org types [%s] and the current orgType is `%s`. Block dropped.',
                    $block->type,
                    $pageSlug,
                    $allowed,
                    $orgType->value,
                ),
            );
        }

        return [$kept, $diagnostics];
    }
}
