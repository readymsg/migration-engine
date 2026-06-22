<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\IrPassAgentResponse;
use App\Data\IrPassInput;

// LLM boundary for the IR pass. Returns whatever the model produced for
// this single call — possibly incomplete. The IrPass orchestration class
// is what diffs the response against expected, runs a targeted retry,
// and decides Complete vs Partial.
//
// Injectable so tests run offline against a deterministic fake.
interface IrPassAgent
{
    public function run(IrPassInput $input): IrPassAgentResponse;
}
