<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\GlobalStyleBrief;
use App\Data\IrBriefDeriverInput;

// LLM boundary for the brief-deriver — produces ONE GlobalStyleBrief for
// the whole site from a bounded sample of representative pages. Returns
// only the brief; chunk designers receive that brief as LOCKED input
// and design IR consistent with it.
//
// Injectable so tests run offline against a deterministic fake.
//
// Failure mode (per IrPass orchestration): if this call throws or
// returns an empty brief, the orchestration falls back to an empty
// brief AND a `*style_brief*` IrPassFailure, then runs the per-chunk
// IR-design calls anyway. Per-page IR still ships (no coherence anchor,
// but useful), conversion goes Partial. Hard-aborting on brief
// failure would throw away the per-page IR work over a coherence
// problem — the user's "partial output beats nothing" call.
interface IrBriefDeriverAgent
{
    public function run(IrBriefDeriverInput $input): GlobalStyleBrief;
}
