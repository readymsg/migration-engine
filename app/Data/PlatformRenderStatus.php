<?php

declare(strict_types=1);

namespace App\Data;

// Result-level signal for the deterministic PlatformBlockRenderer.
// Mirrors AssemblyStatus / BlockFillStatus / IrPassStatus but has NO
// `Failed` case: the renderer is a pure-code closed-table lookup over
// PlatformDynamic ledger entries — no upstream signal can fail it
// wholesale, no LLM call can abort. Either every entry rendered
// cleanly (Complete) or at least one PlatformRenderFailure was
// surfaced (Partial).
enum PlatformRenderStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
}
