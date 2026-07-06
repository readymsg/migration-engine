<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// What the polling /api/conversions/{id}/status endpoint returns. Snap-
// shot of the conversion's current state, cheap to compute and read
// (fits in a single cache entry).
//
// LOAD-BEARING: this shape is the ENTIRE demo-progress contract. If a
// field is missing the frontend can't render progress; if a field is
// wrong the demo shows a lie. Any new field additions must default
// safely on old cache entries (all new fields nullable).
final class ConversionStatusSnapshot extends Data
{
    /**
     * @param  ?array{done: int, total: int}  $block_fill_progress  populated only during BlockFill stage; null before/after
     */
    public function __construct(
        public string $conversion_id,
        public string $url,
        public int $started_at,
        public ConversionPipelineStage $stage,
        public string $stage_label,
        public int $stage_started_at,
        public int $elapsed_seconds,
        public ?array $block_fill_progress = null,
        public ?string $failure_reason = null,
    ) {}

    public function finalStatus(): string
    {
        return $this->stage->isTerminal() ? $this->stage->value : 'in_progress';
    }
}
