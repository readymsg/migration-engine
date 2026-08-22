<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

// Running tally the mapper populates during transformation. Each
// transformation reports its (inputBlocks, outputBlocks, cause)
// tuple to the audit, so the emitter's BlockDeltaAuditor can
// reconcile without parsing diagnostic messages.
//
// Diagnostic-message-parsing was the previous approach — cheap
// but brittle: Card unfolding is a 1-to-N transformation that
// doesn't emit a diagnostic (too noisy — 14+ per page on
// tbirdhoops), so its delta was invisible to the diagnostic-based
// auditor. The audit trail solves that: every mapper call reports
// what it did.
//
// Attributions carry:
//   - cause: short code identifying the transformation
//   - inputBlocks: how many source blocks entered
//   - outputBlocks: how many contract blocks emitted
//
// A pure 1-to-1 mapping (Text → Text) reports (1, 1). A drop
// (unresolvable Image) reports (1, 0). A Card unfolding reports
// (1, N) where N is the emitted-blocks count.
final class MapperAudit
{
    /** @var array<int, array{cause: string, inputBlocks: int, outputBlocks: int}> */
    private array $attributions = [];

    public function record(string $cause, int $inputBlocks, int $outputBlocks): void
    {
        $this->attributions[] = [
            'cause' => $cause,
            'inputBlocks' => $inputBlocks,
            'outputBlocks' => $outputBlocks,
        ];
    }

    /**
     * @return array<int, array{cause: string, inputBlocks: int, outputBlocks: int}>
     */
    public function all(): array
    {
        return $this->attributions;
    }

    /**
     * @return array<string, array{occurrences: int, inputBlocks: int, outputBlocks: int, delta: int}>
     */
    public function summary(): array
    {
        $summary = [];
        foreach ($this->attributions as $a) {
            $key = $a['cause'];
            if (! isset($summary[$key])) {
                $summary[$key] = ['occurrences' => 0, 'inputBlocks' => 0, 'outputBlocks' => 0, 'delta' => 0];
            }
            $summary[$key]['occurrences']++;
            $summary[$key]['inputBlocks'] += $a['inputBlocks'];
            $summary[$key]['outputBlocks'] += $a['outputBlocks'];
            $summary[$key]['delta'] += ($a['outputBlocks'] - $a['inputBlocks']);
        }

        return $summary;
    }

    public function totalInputBlocks(): int
    {
        return array_sum(array_column($this->attributions, 'inputBlocks'));
    }

    public function totalOutputBlocks(): int
    {
        return array_sum(array_column($this->attributions, 'outputBlocks'));
    }
}
