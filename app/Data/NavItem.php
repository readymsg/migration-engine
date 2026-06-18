<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class NavItem extends Data
{
    public function __construct(
        public string $label,
        public string $page_slug,
        public int $order,
    ) {}
}
