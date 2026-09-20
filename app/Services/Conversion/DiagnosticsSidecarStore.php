<?php

declare(strict_types=1);

namespace App\Services\Conversion;

use App\Data\DiagnosticsSidecar;

// Per-conversion diagnostics sidecar store. v2 moved the diagnostics
// channel out of the envelope; this is where it lands during a live
// conversion run. Written by FinalizeConversionJob after the emitter
// runs; not yet served by an HTTP endpoint (Scott question — see the
// v2 orientation §5).
//
// Cache-backed via the app's default cache repository (Redis in prod,
// array in tests). 24-hour TTL to match ConversionResultStore.
interface DiagnosticsSidecarStore
{
    public function put(string $conversionId, DiagnosticsSidecar $sidecar): void;

    public function get(string $conversionId): ?DiagnosticsSidecar;

    public function forget(string $conversionId): void;
}
