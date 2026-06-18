<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One abstract block in a page IR. Schema-agnostic: `component_type` is abstract intent
// (e.g. "Hero", "Heading", "Card"), NOT Puck prop names. The assembler is the only place
// that turns this + the ComponentSchema into real Puck props.
final class IrBlock extends Data
{
    /**
     * @param  array<int, string>  $asset_refs  S3 keys referenced by this block (resolved later)
     */
    public function __construct(
        public string $component_type,
        public string $content_brief,
        public array $asset_refs = [],
    ) {}
}
