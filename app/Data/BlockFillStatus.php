<?php

declare(strict_types=1);

namespace App\Data;

// Result-level signal for block-fill, mirroring IrPassStatus:
//   - Complete: every IR page has a FilledPage.
//   - Partial:  at least one IR page is missing — either the per-page
//     GeneratePageJob hit a terminal failure OR the ContentRef wasn't
//     resolvable before dispatch. Missing pages are in failures so SCORE
//     & LOG can flag the conversion. v1 NEVER stubs a missing page —
//     a visible failure beats a fake rebuild.
//   - Failed:   the orchestration aborted before dispatching any job
//     (e.g. the input IrPassResult was itself Failed, or pre-flight found
//     no work to do AND no recoverable state). Every expected page lands
//     in failures with the abort reason.
enum BlockFillStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Failed = 'failed';
}
