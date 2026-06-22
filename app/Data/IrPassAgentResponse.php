<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Raw output of one IrPassAgent call — what the LLM returned. The IrPass
// orchestration class wraps this in IrPassResult after diffing against
// the expected keep_pages and (optionally) running a targeted retry to
// recover any silently-dropped pages.
final class IrPassAgentResponse extends Data
{
    /**
     * @param  DataCollection<int, Ir>  $pages  whatever the model returned this call (may be incomplete)
     */
    public function __construct(
        public GlobalStyleBrief $style_brief,
        #[DataCollectionOf(Ir::class)]
        public DataCollection $pages,
    ) {}
}
