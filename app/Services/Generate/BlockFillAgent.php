<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\BlockFillInput;
use App\Data\FilledPage;

// LLM boundary for per-page block-fill. Returns ONE FilledPage for the
// single Ir page handed in — the agent never sees other pages.
//
// Injectable so tests run offline against FakeBlockFillAgent. Terminal
// failures (malformed JSON, schema-validation fail, agent exception) are
// thrown by the agent and caught by the GeneratePageJob, which records a
// BlockFillFailure so reconciliation can flag the page.
interface BlockFillAgent
{
    public function run(BlockFillInput $input): FilledPage;
}
