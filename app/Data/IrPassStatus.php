<?php

declare(strict_types=1);

namespace App\Data;

// Result-level signal for the IR pass:
//   - Complete: every Keep content page got IR.
//   - Partial: at least one Keep content page is missing from the result
//     — either because the agent dropped it even after one targeted retry,
//     OR because its body was never captured at ingest (no ContentRef /
//     ContentExtractionFailure / unreadable body). Those pages are listed
//     in IrPassResult.failures so downstream scoring and the ConversionLog
//     can surface them. v1 NEVER stubs a missing page with placeholder
//     content; a visible failure is preferred to a fake rebuild.
//   - Failed: the conversion was aborted before any Opus call. v1 only
//     supports a single-call IR pass; a site whose Keep content page
//     count exceeds that capacity FAILS LOUDLY rather than silently
//     truncating to the first N. Chunking is a later slice (2b). Every
//     Keep content page lands in failures with the over-capacity reason.
enum IrPassStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Failed = 'failed';
}
