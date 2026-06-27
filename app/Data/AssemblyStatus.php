<?php

declare(strict_types=1);

namespace App\Data;

// Result-level signal for the deterministic assembler, mirroring
// BlockFillStatus / IrPassStatus:
//   - Complete: every FilledPage produced a clean PuckOutput, no blocks
//     dropped, no upstream block-fill failures.
//   - Partial:  at least one block was substituted or dropped (so a
//     block_issue was recorded), OR at least one whole page became an
//     AssemblyFailure (zero valid blocks left), OR a BlockFillFailure
//     was chained through from upstream. The conversion still produces
//     PuckOutputs for the survivable pages.
//   - Failed:   upstream BlockFillResult was itself Failed (e.g. IR-pass
//     over-capacity abort propagated). No PuckOutputs emitted; every
//     upstream failure surfaces as an AssemblyFailure.
enum AssemblyStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Failed = 'failed';
}
