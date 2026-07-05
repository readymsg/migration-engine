<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Raw output of ONE chunk-designer call. Just the per-page IR for the
// chunk's pages — NO style_brief (that's the brief-deriver's job and is
// locked input here). IrPass orchestration diffs returned pages against
// the chunk's expected slugs and runs a per-chunk targeted retry on
// silent drops, same pattern the single-call IR pass used.
final class IrChunkDesignerResponse extends Data
{
    /**
     * @param  DataCollection<int, Ir>  $pages  whatever the model returned this chunk-call (may be incomplete; orchestration diffs against expected)
     */
    public function __construct(
        #[DataCollectionOf(Ir::class)]
        public DataCollection $pages,
    ) {}
}
