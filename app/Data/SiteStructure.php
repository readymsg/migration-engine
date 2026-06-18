<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class SiteStructure extends Data
{
    /**
     * @param  DataCollection<int, NavNode>  $nav  rootNav-derived tree
     */
    public function __construct(
        #[DataCollectionOf(NavNode::class)]
        public DataCollection $nav,
        public int $pages_total,
    ) {}
}
