<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class DecisionLedger extends Data
{
    /**
     * @param  DataCollection<int, DecisionEntry>  $entries
     */
    public function __construct(
        #[DataCollectionOf(DecisionEntry::class)]
        public DataCollection $entries,
    ) {}
}
