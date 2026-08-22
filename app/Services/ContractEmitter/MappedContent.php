<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Block;
use App\Data\SiteImport\Diagnostic;

// Return type of PuckToContractMapper::mapContent(). Carries the
// translated Block[] plus any Diagnostic entries the mapper
// generated during translation (unmappable blocks, unresolved asset
// URLs, flattened columns — every drop is a diagnostic so a
// reviewer can see WHY the output looks the way it does).
final class MappedContent
{
    /**
     * @param  array<int, Block>  $blocks
     * @param  array<int, Diagnostic>  $diagnostics
     */
    public function __construct(
        public readonly array $blocks,
        public readonly array $diagnostics,
    ) {}
}
