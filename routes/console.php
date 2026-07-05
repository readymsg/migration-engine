<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Correctness backstop for the async block-fill pipeline. Every minute
// (per the async slice's 1-min sweeper SLA), scan job_batches for
// block-fill batches whose callback failed to fire OR whose batches are
// stuck past the STUCK_THRESHOLD_MINUTES budget. reconcile() is
// idempotent by design so overlapping ticks are safe. See
// ReconcileStuckConversionsCommand for the two duties this covers.
//
// withoutOverlapping: prevents a slow sweeper tick (large job_batches
// table on a heavily-loaded engine) from stacking with the next tick.
// onOneServer: guards against multi-Horizon deployments each running
// the same sweeper.
Schedule::command('engine:reconcile-stuck-conversions')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
