<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class DecisionEntry extends Data
{
    public function __construct(
        public string $target,
        public DecisionAction $action,
        public string $reason,
        public float $confidence,
        public ?string $merged_into = null,
    ) {}
}
