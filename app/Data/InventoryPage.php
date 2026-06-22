<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One row of the page inventory built from a Manifest's nav tree. Carries
// enough signal that the classifier can decide deterministically OR send to
// the LLM without re-walking the tree.
final class InventoryPage extends Data
{
    /**
     * @param  array<int, string>  $nav_path  ordered ancestor labels (excludes own label)
     */
    public function __construct(
        public string $label,
        public ?string $url,
        public string $kind,                 // derived: page | dynamic_calendar | dynamic_news | dynamic_other | external | unknown
        public ?string $node_type,           // raw SE: Page | Calendar | NewsNode | LinkNode | null | other
        public ?int $page_node_id,
        public ?string $external_subtype,    // external_link | se_tool | null (only when kind=external)
        public int $depth,
        public array $nav_path,
        // True when the original NavNode had child entries. Lets the
        // platform_dynamic name-map gate ambiguous matches (e.g. a leaf
        // "Teams" page is content; a "Teams" parent of team pages is a
        // PlatformBlockType::Teams directory).
        public bool $has_children = false,
    ) {}
}
