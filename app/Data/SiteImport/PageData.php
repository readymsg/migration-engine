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
// root + zones are typed `array` on this DTO so PHP code can compare
// `$page->data->root === []` cheaply. The critical fact: spatie's
// default transformer would serialise typed-empty-array as JSON `[]`,
// which the schema rejects (`$defs/pageData` demands `type: object,
// maxProperties: 0`). To close that shipped-payload bug (present in
// v1 too), all envelope encoding routes through `EnvelopeJson::encode()`,
// which rewrites empty root/zones to `\stdClass` at the encode seam so
// json_encode emits `{}`. See EnvelopeJson for the single-source rule.
final class PageData extends Data
{
    /**
     * @param  DataCollection<int, Block>  $content
     * @param  array<string, mixed>  $root  MUST be `[]` here; EnvelopeJson wraps as JSON `{}` at encode time
     * @param  array<string, mixed>  $zones  MUST be `[]` here; EnvelopeJson wraps as JSON `{}` at encode time
     */
    public function __construct(
        #[DataCollectionOf(Block::class)]
        public DataCollection $content,
        public array $root = [],
        public array $zones = [],
    ) {}
}
