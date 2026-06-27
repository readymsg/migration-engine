<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// One block-fill agent's output for a single page. `blocks` is the ordered
// list of FilledBlocks the agent emitted from the page's Ir + body.
//
// `self_assessment` + `confidence` are the in-pass self-critique signals
// BUILD.md asks for. SOFT — never gated on (a low-confidence page still
// ships, flagged via the trusted structuralConfidence at SCORE & LOG time).
// Same posture as the rest of the engine: trust deterministic/structural
// signals over LLM self-report.
final class FilledPage extends Data
{
    /**
     * @param  DataCollection<int, FilledBlock>  $blocks
     */
    public function __construct(
        public string $page_slug,
        public string $page_title,
        public int $nav_order,
        #[DataCollectionOf(FilledBlock::class)]
        public DataCollection $blocks,
        public string $self_assessment,
        public float $confidence,
    ) {}
}
