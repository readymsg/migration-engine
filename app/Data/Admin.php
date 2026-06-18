<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// Admin emails are PII. Per BUILD.md: keep out of general logs; redact/scope retention.
final class Admin extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $role = null,
    ) {}
}
