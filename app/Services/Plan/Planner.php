<?php

declare(strict_types=1);

namespace App\Services\Plan;

use App\Data\Manifest;
use App\Data\SitePlan;

// Stage 3 PLAN entrypoint. Pure orchestration: inventory → classify → decideIa.
// The IA decision is deterministic in v1 (preserve SE's top-level order, drop
// nothing destructively); the "what's the IA" LLM prompt that BUILD.md
// describes is deferred to a later iteration. The keep/drop prompt IS active,
// behind the ClassifierAgent seam, with recall-bias enforced in code.
interface Planner
{
    public function plan(Manifest $manifest): SitePlan;
}
