<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class Provisioning extends Data
{
    /**
     * @param  DataCollection<int, Team>  $teams
     * @param  DataCollection<int, Division>  $divisions
     * @param  DataCollection<int, Admin>  $admins  PII — see Admin
     */
    public function __construct(
        #[DataCollectionOf(Team::class)]
        public DataCollection $teams,
        #[DataCollectionOf(Division::class)]
        public DataCollection $divisions,
        #[DataCollectionOf(Admin::class)]
        public DataCollection $admins,
    ) {}
}
