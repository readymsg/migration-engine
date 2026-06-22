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
        // Set only when action === PlatformDynamic. Tells GENERATE which
        // TeamLinkt Puck block to instantiate in place of the source page.
        public ?PlatformBlockType $platform_block_type = null,
    ) {}
}
