<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One Keep content page that the IR pass could not produce IR for, even
// after a targeted retry. Recorded explicitly so the ConversionLog and
// any scoring logic can flag the conversion as Partial. NEVER replaced
// by a stub Ir entry.
final class IrPassFailure extends Data
{
    public function __construct(
        public string $page_slug,
        public string $page_title,
        public ?int $page_node_id,
        public string $reason,
    ) {}
}
