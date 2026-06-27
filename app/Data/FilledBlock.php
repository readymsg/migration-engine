<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One filled block: the per-block-fill agent's output for ONE block in an Ir.
// `component_type` is SCHEMA-NAMED (e.g. 'Hero', 'Heading', 'Card') — the
// agent has resolved the IR's abstract intent (e.g. 'hero') to a concrete
// ComponentSchema entry and emitted props matching that entry's FieldDefinitions.
// The deterministic assembler is the next slice and turns this into Puck JSON.
//
// `source_quote` is the body snippet the agent cites as the anchor for this
// block's content. Empty is allowed for prop-style blocks (CTA labels, image
// captions). Empty is FLAGGED for content blocks (Hero/Heading/Text/Card) —
// fabrication guard: no body anchor on real copy means we can't show it came
// from the source.
final class FilledBlock extends Data
{
    /**
     * @param  array<string, mixed>  $props  shape conforms to ComponentSchema::get($component_type)->fields
     */
    public function __construct(
        public string $component_type,
        public array $props,
        public string $source_brief,
        public ?string $source_quote = null,
    ) {}
}
