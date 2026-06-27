<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Output of GENERATE stage 3 slice 2c (block-fill): per-page FilledPage for
// every Ir page the IR pass produced, plus an explicit BlockFillFailure for
// any page that didn't make it through. The deterministic assembler reads
// this in the next slice and turns FilledPages into PuckOutput.
//
// Faithful-rebuild guarantee — same as IrPassResult: every expected page is
// in `pages` OR `failures`, exactly once. No silent absences, no stubs.
// Reconciliation is the authority, NOT Bus::batch's success flag — the
// orchestration diffs returned slugs against the input IrPassResult.pages.
final class BlockFillResult extends Data
{
    /**
     * @param  DataCollection<int, FilledPage>  $pages
     * @param  DataCollection<int, BlockFillFailure>  $failures
     */
    public function __construct(
        public GlobalStyleBrief $style_brief,
        #[DataCollectionOf(FilledPage::class)]
        public DataCollection $pages,
        #[DataCollectionOf(BlockFillFailure::class)]
        public DataCollection $failures,
        public BlockFillStatus $status,
    ) {}
}
