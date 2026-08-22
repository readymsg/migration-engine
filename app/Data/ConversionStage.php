<?php

declare(strict_types=1);

namespace App\Data;

// Which stage of the pipeline a ConversionFailure originated in. Lets
// SCORE & LOG group failures by stage when summarizing a conversion —
// e.g. "3 of 7 pages missing: 2 from block-fill, 1 from assembler".
//
// Order roughly matches the pipeline flow; values are the lowercased
// stage names that already appear in upstream failure reason prefixes
// (e.g. AssemblyFailure carries 'block-fill-failure: …' / 'ir-pass-
// failure: …' — the same vocabulary).
enum ConversionStage: string
{
    case IrPass = 'ir-pass';
    case BlockFill = 'block-fill';
    case Assembler = 'assembler';
    case PlatformRender = 'platform-render';
    case DraftLanding = 'draft-landing';
    // The new post-DraftLanding stage: ContractPayloadEmitter
    // produces the Site Import Contract v1 Envelope. Validation
    // errors from the emitter surface as ConversionFailures with
    // this stage so the reviewer sees them alongside every other
    // pipeline failure.
    case ContractEmit = 'contract-emit';
}
