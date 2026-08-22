<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

// Result of BlockDeltaAuditor::audit(). Reconciles three counts:
//   - source input:      what the ConversionResult page_map contained
//   - audited input:     what the mapper reported seeing
//   - envelope output:   what the finished payload contains (deep)
//   - audited output:    what the mapper reported emitting
//
// A "reconciled" report has (source input == audited input) AND
// (envelope output == audited output). Any mismatch is a silent-
// drop signal.
final class BlockDeltaReport
{
    /**
     * @param  array<string, array{occurrences: int, inputBlocks: int, outputBlocks: int, delta: int}>  $attributions  MapperAudit::summary() output
     */
    public function __construct(
        public readonly int $blocksIn,
        public readonly int $blocksOut,
        public readonly int $inputAudited,
        public readonly int $outputAudited,
        public readonly int $actualDelta,
        public readonly int $inputMismatch,
        public readonly int $outputMismatch,
        public readonly array $attributions,
    ) {}

    public function isReconciled(): bool
    {
        return $this->inputMismatch === 0 && $this->outputMismatch === 0;
    }
}
