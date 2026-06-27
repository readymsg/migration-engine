<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\GlobalStyleBrief;

// Per-conversion side store for the GlobalStyleBrief. Written once by the
// BlockFill orchestrator before the batch dispatch; read by every
// GeneratePageJob in the batch. Kept OUT of the queue payload deliberately
// — embedding the brief in every job row would bloat the queue table.
//
// Keyed by conversion_id. Each conversion has its own brief; concurrent
// conversions don't conflict.
interface BlockFillContextStore
{
    public function put(string $conversionId, GlobalStyleBrief $brief): void;

    /**
     * @throws \RuntimeException when the brief is missing — a missing
     *                           brief means the orchestrator's pre-batch step was skipped
     *                           or the conversion was reaped. Jobs should fail loudly
     *                           rather than silently fall back to an empty brief.
     */
    public function get(string $conversionId): GlobalStyleBrief;

    public function forget(string $conversionId): void;
}
