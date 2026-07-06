<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// Hand-off DTO between ConversionJob and FinalizeConversionJob when
// they run in DIFFERENT PROCESSES (which is the whole point of the
// step-6 async chain — ConversionJob dispatches the block-fill batch
// and returns; FinalizeConversionJob runs later, on a worker, after
// reconcile fires).
//
// Carries the state FinalizeConversionJob needs from ConversionJob's
// pre-batch stages: the Manifest (for platform-render + draft-landing)
// and the SitePlan (for the same). The IrPassResult is already carried
// by BlockFillReconcileState in the block-fill store and doesn't need
// to be re-persisted here.
//
// Same posture as BlockFillReconcileState — cross-process cache
// hand-off, JSON-serialized via spatie/laravel-data.
final class ConversionContext extends Data
{
    public function __construct(
        public string $conversion_id,
        public string $url,
        public Manifest $manifest,
        public SitePlan $plan,
    ) {}
}
