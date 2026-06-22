<?php

declare(strict_types=1);

namespace App\Data;

// Result-level signal for the IR pass:
//   - Complete: every Keep content page got IR.
//   - Partial: at least one Keep content page is missing from the result
//     EVEN AFTER one targeted retry. Those pages are listed in
//     IrPassResult.failures so downstream scoring and the ConversionLog
//     can surface them. v1 NEVER stubs a missing page with placeholder
//     content; a visible failure is preferred to a fake rebuild.
enum IrPassStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
}
