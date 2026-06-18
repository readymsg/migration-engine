<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class NavNode extends Data
{
    /**
     * @param  DataCollection<int, NavNode>  $children
     */
    public function __construct(
        public string $label,
        public ?string $url,
        // TODO: tighten to an enum once the rootNav classifier lands; current values:
        // 'page' | 'dynamic' | 'external' | 'unknown'.
        public string $kind,
        #[DataCollectionOf(NavNode::class)]
        public DataCollection $children,
    ) {}
}
