<?php

declare(strict_types=1);

namespace App\Services\Plan;

use App\Data\ClassificationResponse;
use App\Data\InventoryPage;

// Boundary between the planner and the LLM. Only ambiguous content pages
// (kind=page) flow through here — external/dynamic dispositions are decided
// deterministically by the planner and never sent to the model. The
// interface is injectable so tests run offline against a Fake.
interface ClassifierAgent
{
    /**
     * Classify a batch of ambiguous content pages. Returned array is aligned
     * with the input order; one entry per input page.
     *
     * @param  array<int, InventoryPage>  $batch
     * @return array<int, ClassificationResponse>
     */
    public function classifyBatch(array $batch, string $brandVoiceHint = ''): array;
}
