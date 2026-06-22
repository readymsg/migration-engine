<?php

declare(strict_types=1);

namespace App\Services\Extract;

use Spatie\LaravelData\Data;

// One HTML fetch. `final_url` is the post-redirect URL — BUILD.md guardrail:
// the Manifest records where the conversion actually ended up (e.g. a
// strikersbaseball.ca → langdondiamonds.ca 301 rebrand).
final class HtmlFetchResult extends Data
{
    public function __construct(
        public string $requested_url,
        public string $final_url,
        public string $html,
        public int $status,
    ) {}
}
