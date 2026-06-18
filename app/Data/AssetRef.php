<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class AssetRef extends Data
{
    public function __construct(
        public string $s3_key,
        public string $mime_type,
        public ?string $source_url = null,
        public ?int $bytes = null,
        public ?int $width = null,
        public ?int $height = null,
    ) {}
}
