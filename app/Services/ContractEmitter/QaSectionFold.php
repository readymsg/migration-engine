<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Block;

// Result of PuckToContractMapper::tryFoldToQaSection. Carries the
// chosen block (FAQ or Accordion) — the caller reads the block type
// to decide the diagnostic code without inspecting props internals.
//
// Same posture as the other mapper folds returning a Block directly,
// but this one has to encode the "which target won" signal in the
// return so the diagnostic reflects the branch — the block's `type`
// field is that signal.
final class QaSectionFold
{
    public function __construct(
        public readonly Block $block,
    ) {}
}
