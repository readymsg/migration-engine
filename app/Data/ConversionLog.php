<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One row per conversion. Structured for Metabase per BUILD.md stage 4.
final class ConversionLog extends Data
{
    /**
     * @param  array<string, float>  $stage_confidences  e.g. ['ingest' => 0.92, 'plan' => 0.81, ...]
     * @param  array<string, float>  $page_scores  page_slug => structural_confidence
     * @param  array<string, int>  $token_usage  e.g. ['prompt' => 12000, 'completion' => 3400, 'cached' => 9000]
     */
    public function __construct(
        public string $conversion_id,
        public string $org_id,
        public string $source_url,
        public ConversionStatus $status,
        public array $stage_confidences,
        public DecisionLedger $decision_ledger,
        public array $page_scores,
        public int $duration_ms,
        public float $ai_cost_usd,
        public array $token_usage,
        public ?string $failure_reason = null,
        public ?string $draft_link = null,
    ) {}
}
