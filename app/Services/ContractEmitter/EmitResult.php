<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Envelope;
use App\Data\SiteImport\ValidationIssue;

// Return type of ContractPayloadEmitter::emit(). Carries the built
// envelope PLUS the validation verdict — envelope is always
// produced (a partial payload is more useful for iteration than a
// hard error), but callers can refuse to ship one that has errors.
//
// Two distinct issue channels are kept separate on purpose:
//
//   $errors + $warnings — ContractSchemaValidator's verdict about
//     whether the envelope is ingest-legal. This is the pre-ship
//     gate. If $errors is non-empty, the payload will be rejected
//     by TeamLinkt's ingest validator.
//
//   envelope.diagnostics — WHAT WAS LOST during translation
//     (scrubs, unmappable blocks, hero drops, etc). This is the
//     reviewer-visible channel that lives INSIDE the payload.
//
// A payload can have zero validation errors AND many diagnostics —
// the site translated cleanly but with visible drops. And a payload
// can have many validation errors AND many diagnostics — inspect
// both before deciding.
final class EmitResult
{
    /**
     * @param  array<int, ValidationIssue>  $errors
     * @param  array<int, ValidationIssue>  $warnings
     */
    public function __construct(
        public readonly Envelope $envelope,
        public readonly array $errors,
        public readonly array $warnings,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
