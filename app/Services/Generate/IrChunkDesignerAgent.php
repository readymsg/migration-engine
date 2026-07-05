<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\IrChunkDesignerInput;
use App\Data\IrChunkDesignerResponse;

// LLM boundary for ONE chunked IR-design call. Receives a locked
// GlobalStyleBrief + a bounded chunk of keep-content pages; returns
// per-page IR for the chunk. NO style brief in the response — the
// brief is upstream and locked.
//
// IrPass orchestration runs K of these per site (K = ceil(N/CHUNK_PAGE_LIMIT)),
// diffs each chunk's returned slugs against its expected slugs, runs a
// per-chunk targeted retry on silent drops, and unions all chunks for
// the aggregate IrPassResult. A catastrophic chunk-level throw is
// caught by orchestration and turned into one IrPassFailure per page
// in that chunk — never silently absent.
//
// Injectable so tests run offline against a deterministic fake.
interface IrChunkDesignerAgent
{
    public function run(IrChunkDesignerInput $input): IrChunkDesignerResponse;
}
