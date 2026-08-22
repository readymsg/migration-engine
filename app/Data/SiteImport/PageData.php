<?php

declare(strict_types=1);

namespace App\Data\SiteImport;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Puck's `Data` shape for one page. Contract Part II "`data`":
//   - content: the page's blocks, in render order. This is the
//     whole job of translation.
//   - root: ALWAYS `{}`. Site chrome is spliced into page root props
//     by the builder at load time; anything sent here is overwritten.
//   - zones: ALWAYS `{}`. Legacy Puck nesting field. Nesting goes in
//     slot props (Grid.column1, Tabs.tab1, Section.content,
//     Table.rows[].cells[].content) instead.
//
// root + zones are typed `array` so they serialise as JSON `{}` when
// empty. The validator enforces both are actually empty; the DTO
// doesn't refuse a value structurally so tests can prove the guard
// works.
final class PageData extends Data
{
    /**
     * @param  DataCollection<int, Block>  $content
     * @param  array<string, mixed>  $root  MUST be `[]` in a valid payload; overwritten at load time regardless.
     * @param  array<string, mixed>  $zones  MUST be `[]` in a valid payload; nesting lives in slot props, not zones.
     */
    public function __construct(
        #[DataCollectionOf(Block::class)]
        public DataCollection $content,
        public array $root = [],
        public array $zones = [],
    ) {}
}
