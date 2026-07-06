<?php

declare(strict_types=1);

namespace App\Services\Conversion;

use App\Data\ConversionPipelineStage;
use App\Data\ConversionStatusSnapshot;

// Per-conversion pipeline-progress store. Written at each stage
// boundary by ConversionJob + FinalizeConversionJob + block-fill
// callbacks; read by the /api/conversions/{id}/status endpoint.
//
// LOAD-BEARING for the demo's "no silent hang" property: every stage
// entry MUST have a matching writeStage/writeFailure/writeTerminal
// call. A non-terminal stage that never advances is the exact
// worst-case demo failure this contract exists to prevent.
interface ConversionStatusStore
{
    /**
     * First write for a conversion — begins the timer. url captured so
     * the status blob is self-describing (frontend can show "Rebuilding
     * https://…" without re-fetching).
     */
    public function begin(string $conversionId, string $url): void;

    /**
     * Non-terminal stage transition (Ingest / Plan / IrPass / BlockFill /
     * Finalize). Updates stage + stage_started_at. Preserves prior
     * started_at + url.
     */
    public function advance(string $conversionId, ConversionPipelineStage $stage): void;

    /**
     * Terminal — Complete or Partial. Locks the snapshot; subsequent
     * advance calls are no-ops (idempotent — protects against sweeper
     * re-drives finalizing a conversion that already terminated).
     */
    public function complete(string $conversionId, ConversionPipelineStage $terminalStage): void;

    /**
     * Terminal — Failed. Writes failure_reason. Idempotent (first
     * failure wins so subsequent throws don't overwrite the root cause).
     */
    public function fail(string $conversionId, string $failureReason): void;

    /**
     * Update block-fill progress mid-stage. Called from ConversionStatus
     * computation, NOT from GeneratePageJob (that would put a
     * status-store dependency on every per-page job — too much
     * coupling). Computed lazily from block-fill cache counts when the
     * status endpoint reads.
     *
     * @param  array{done: int, total: int}  $progress
     */
    public function updateBlockFillProgress(string $conversionId, array $progress): void;

    public function get(string $conversionId): ?ConversionStatusSnapshot;
}
