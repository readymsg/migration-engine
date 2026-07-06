<?php

declare(strict_types=1);

namespace App\Services\Conversion;

use App\Data\ConversionContext;

// Per-conversion context hand-off store. Written by ConversionJob
// (after INGEST + PLAN), read by FinalizeConversionJob (which runs on
// a different worker process after block-fill's batch completes).
//
// Same pattern as BlockFillReconcileState / BlockFillResultStore's
// reconciled namespace: cross-process cache hand-off, JSON-serialized.
interface ConversionContextStore
{
    public function put(string $conversionId, ConversionContext $context): void;

    public function get(string $conversionId): ?ConversionContext;

    public function forget(string $conversionId): void;
}
