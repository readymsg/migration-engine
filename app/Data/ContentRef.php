<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class ContentRef extends Data
{
    /**
     * @param  array<int, string>  $nav_path  ordered breadcrumb of nav labels from rootNav
     */
    public function __construct(
        public string $url,
        public string $scrape_ref,
        public ?string $title = null,
        public array $nav_path = [],
    ) {}
}
