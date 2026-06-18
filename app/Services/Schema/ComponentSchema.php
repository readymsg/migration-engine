<?php

declare(strict_types=1);

namespace App\Services\Schema;

use App\Data\ComponentDefinition;

// Single source of block types + prop shapes for the engine.
// Today: hand-written default-Puck config. Later: real fetched export from the product.
// The assembler is the ONLY place that maps abstract IR + this schema → Puck JSON.
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
}
