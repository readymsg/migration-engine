<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

// AssetLedger::register()'s return shape. A rejection carries a
// specific reason so the diagnostics collector (Slice 8) can turn
// it into a `warning` diagnostic without losing WHY the asset was
// dropped. An acceptance carries the ref so the caller can emit
// the tl-asset:<ref> token in props.
final class RegistrationResult
{
    private function __construct(
        public readonly bool $rejected,
        public readonly ?string $ref,
        public readonly ?string $reason,
    ) {}

    public static function accepted(string $ref): self
    {
        return new self(rejected: false, ref: $ref, reason: null);
    }

    public static function rejected(string $reason): self
    {
        return new self(rejected: true, ref: null, reason: $reason);
    }
}
