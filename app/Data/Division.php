<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class Division extends Data
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $season = null,
    ) {}
}
