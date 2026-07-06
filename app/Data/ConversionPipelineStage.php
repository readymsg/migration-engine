<?php

declare(strict_types=1);

namespace App\Data;

// Ordered pipeline stages a conversion moves through, as tracked by
// ConversionStatusStore for the polling /status endpoint. Distinct
// from ConversionStage (which is failure-attribution — which stage
// PRODUCED a failure, present on ConversionFailure).
//
// Progression under normal execution:
//   Queued → Ingest → Plan → IrPass → BlockFill → Finalize → Complete|Partial
// Any stage can fail → Failed with a failure_reason.
enum ConversionPipelineStage: string
{
    case Queued = 'queued';
    case Ingest = 'ingest';
    case Plan = 'plan';
    case IrPass = 'ir_pass';
    case BlockFill = 'block_fill';
    case Finalize = 'finalize';
    case Complete = 'complete';
    case Partial = 'partial';
    case Failed = 'failed';

    // Terminal = the polling frontend stops here. Non-terminal = keep
    // polling. This is the LOAD-BEARING check for "no silent hang" —
    // any conversion sitting in a non-terminal stage past a reasonable
    // wall-clock should surface as Failed via the sweeper or job
    // failed() hooks, never sit forever.
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Complete, self::Partial, self::Failed => true,
            default => false,
        };
    }

    /**
     * Human-readable label for the polling status. What the demo UI
     * shows in its "current step" line.
     */
    public function humanLabel(): string
    {
        return match ($this) {
            self::Queued => 'Queued — starting up',
            self::Ingest => 'Reading your site structure',
            self::Plan => 'Planning the rebuild',
            self::IrPass => 'Designing pages',
            self::BlockFill => 'Rebuilding pages with real content',
            self::Finalize => 'Assembling the draft',
            self::Complete => 'Complete',
            self::Partial => 'Complete with some pages flagged',
            self::Failed => 'Failed',
        };
    }
}
