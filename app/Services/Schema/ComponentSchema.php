<?php

declare(strict_types=1);

namespace App\Services\Schema;

use App\Data\ComponentDefinition;

// Single source of block types + prop shapes for the engine.
// Today: hand-written default-Puck config. Later: real fetched export from the product
// (which will land as one export carrying both content and platform sets).
//
// TWO SCOPED METHODS, ONE PROVIDER:
//
//   - all() / get() / types() return CONTENT components — the closed set the
//     block-fill LLM may emit. The assembler validates against this set.
//
//   - platformBlocks() returns PLATFORM components — the closed set the
//     PlatformBlockRenderer constructs from PLAN's PlatformDynamic ledger
//     entries. The block-fill LLM is NEVER told about these; they're
//     constructed deterministically, never filled from scraped copy.
//
// "The assembler is the one schema-aware validation point" holds correctly
// scoped: validate→coerce→re-validate runs over content blocks (where
// fabrication risk lives). Platform blocks are emitted from a closed table
// — no LLM, no fabrication, no validation pipeline needed.
interface ComponentSchema
{
    /**
     * @return array<string, ComponentDefinition>  keyed by component type (e.g. "Hero")
     */
    public function all(): array;

    public function get(string $componentType): ?ComponentDefinition;

    /**
     * @return array<int, string>
     */
    public function types(): array;

    /**
     * Platform-block component definitions, keyed by Puck type (e.g.
     * "PlatformSchedule"). Disjoint from all() by construction: platform
     * blocks are constructed by PlatformBlockRenderer from PLAN, NOT
     * emitted by the block-fill LLM.
     *
     * @return array<string, ComponentDefinition>
     */
    public function platformBlocks(): array;
}
