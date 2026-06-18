<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Per-page IR. Ordered blocks plus this page's position in the nav.
// Schema-agnostic — abstract intent only, never Puck prop names.
final class Ir extends Data
{
    /**
     * @param  DataCollection<int, IrBlock>  $blocks  ordered top-to-bottom
     */
    public function __construct(
        public string $page_slug,
        public string $page_title,
        public int $nav_order,
        #[DataCollectionOf(IrBlock::class)]
        public DataCollection $blocks,
    ) {}
}
