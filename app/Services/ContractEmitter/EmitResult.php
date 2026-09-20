<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\DiagnosticsSidecar;
use App\Data\SiteImport\Envelope;
use App\Data\SiteImport\ValidationIssue;

// Return type of ContractPayloadEmitter::emit(). Carries the built
// envelope PLUS the validation verdict PLUS the diagnostics sidecar
// (v2 moved diagnostics out of the envelope — the channel didn't
// disappear, it moved to its own DTO). The envelope is always
// produced (a partial payload is more useful for iteration than a
// hard error), but callers can refuse to ship one that has errors.
//
// Two distinct issue channels are kept separate on purpose:
//
//   $errors + $warnings — ContractPayloadValidator (schema) +
//     ContractSchemaValidator (per-block) verdict about whether the
//     envelope is ingest-legal. This is the pre-ship gate. If $errors
//     is non-empty, the payload will be rejected by TeamLinkt's ingest.
//
//   $sidecar->diagnostics — WHAT WAS LOST during translation (scrubs,
//     unmappable blocks, hero drops, palette fallbacks, etc). Now
//     persisted alongside the envelope, NOT inside it.
//
// A payload can have zero validation errors AND many sidecar
// diagnostics (site translated cleanly but with visible drops), or
// many of each — inspect both before deciding.
final class EmitResult
{
    /**
     * @param  array<int, ValidationIssue>  $errors
     * @param  array<int, ValidationIssue>  $warnings
     */
    public function __construct(
        public readonly Envelope $envelope,
        public readonly DiagnosticsSidecar $sidecar,
        public readonly array $errors,
        public readonly array $warnings,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
