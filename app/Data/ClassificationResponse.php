<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One LLM classification result, returned in batch from the ClassifierAgent.
// Not the same as a DecisionEntry: this is the raw model output; the planner
// applies the recall-bias and reversibility rules and produces the
// DecisionEntry that lands in the ledger.
final class ClassificationResponse extends Data
{
    public function __construct(
        public DecisionAction $action,   // keep | merge | drop | park  (LLM never returns 'dynamic')
        public float $confidence,        // 0..1
        public string $reason,           // one line
        public ?string $merged_into = null,
    ) {}
}
