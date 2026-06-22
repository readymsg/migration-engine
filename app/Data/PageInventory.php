<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class PageInventory extends Data
{
    /**
     * @param  DataCollection<int, InventoryPage>  $pages  pre-order traversal of the manifest's nav tree
     */
    public function __construct(
        #[DataCollectionOf(InventoryPage::class)]
        public DataCollection $pages,
    ) {}
}
