<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Output of stage 3 GENERATE's irPass — a compact GlobalStyleBrief plus
// per-page IR for every Keep content page. Built by the IrPass orchestration
// from one or more IrPassAgent calls. BUILD.md uses the style brief as the
// main coherence lever in v1: it gets injected into every block-fill call so
// pages don't drift in tone or layout.
//
// Faithful-rebuild guarantee: if the LLM drops a page across the initial
// call AND one targeted retry, the missing page is recorded in `failures`
// — NEVER silently absent, NEVER stubbed with placeholder content. The
// caller can read `status === Partial` to mark the conversion accordingly.
final class IrPassResult extends Data
{
    /**
     * @param  DataCollection<int, Ir>  $pages  one per Keep content page that the agent returned (across initial + retry); never includes platform_dynamic / subsumed / parked / stub
     * @param  DataCollection<int, IrPassFailure>  $failures  Keep content pages the agent never produced IR for, even after one retry
     */
    public function __construct(
        public GlobalStyleBrief $style_brief,
        #[DataCollectionOf(Ir::class)]
        public DataCollection $pages,
        #[DataCollectionOf(IrPassFailure::class)]
        public DataCollection $failures,
        public IrPassStatus $status,
    ) {}
}
