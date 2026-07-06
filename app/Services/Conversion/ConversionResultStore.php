<?php

declare(strict_types=1);

namespace App\Services\Conversion;

use App\Data\ConversionResult;

// Per-conversion final-result store. Written by FinalizeConversionJob
// after the full pipeline completes; read by the /api/conversions/{id}
// endpoint when final_status is Complete or Partial.
//
// Also the idempotency marker for FinalizeConversionJob: presence in
// this store means "already finalized, do not re-run" (protects
// against sweeper-driven re-dispatches producing duplicate work).
interface ConversionResultStore
{
    public function put(string $conversionId, ConversionResult $result): void;

    public function get(string $conversionId): ?ConversionResult;

    public function forget(string $conversionId): void;
}
