<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One Ir page that block-fill could not produce a FilledPage for. Recorded
// explicitly so reconciliation can surface every expected page as either
// FilledPage OR BlockFillFailure — never silently absent, never stubbed.
//
// Mirrors IrPassFailure's shape so the ConversionLog can treat IR and
// block-fill failures uniformly.
final class BlockFillFailure extends Data
{
    public function __construct(
        public string $page_slug,
        public string $page_title,
        public ?int $page_node_id,
        public string $reason,
    ) {}
}
