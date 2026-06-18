<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Compact brief emitted by the IR pass and injected into every block-fill call.
// Main site-level coherence lever in a critic-free v1 (BUILD.md stage 3).
final class GlobalStyleBrief extends Data
{
    /**
     * @param  array<string, string>  $palette  color tokens (primary/secondary/accent/…)
     * @param  array<int, string>  $layout_conventions  free-form rules like "Use full-bleed heroes on landing pages"
     * @param  DataCollection<int, NavItem>  $nav  final site nav order
     */
    public function __construct(
        public string $brand_voice,
        public array $palette,
        public array $layout_conventions,
        #[DataCollectionOf(NavItem::class)]
        public DataCollection $nav,
    ) {}
}
