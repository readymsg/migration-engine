<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// Validated Puck data for one page, conforming to the ComponentSchema provider.
// Produced deterministically by the assembler — never by an LLM directly.
final class PuckOutput extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $content  ordered Puck blocks with their final props
     * @param  array<string, mixed>  $root  Puck root props (page-level config)
     * @param  array<string, array<int, array<string, mixed>>>  $zones  optional Puck DropZones
     */
    public function __construct(
        public string $page_slug,
        public array $content,
        public array $root = [],
        public array $zones = [],
    ) {}
}
