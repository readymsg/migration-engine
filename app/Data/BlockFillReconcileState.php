<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Captures the state BlockFill::dispatch() needs to hand off to
// BlockFill::reconcile() when they run in DIFFERENT PROCESSES (which is
// the whole point of the async slice — dispatch writes state to a
// cache-backed store; reconcile runs later on a worker, reads it back).
//
// Under sync queue, dispatch + reconcile run in the same process and the
// state store is a trivial pass-through. Under async, this DTO IS the
// contract between the two — it captures every input reconcile needs so
// the worker-side reconcile doesn't have to re-do preflight or replay IR.
final class BlockFillReconcileState extends Data
{
    /**
     * @param  DataCollection<int, BlockFillFailure>  $preflight_failures
     * @param  array<int, string>  $expected_slugs  every IR page slug that had a job dispatched OR a preflight failure — the diff universe reconcile walks
     */
    public function __construct(
        public string $conversion_id,
        public IrPassResult $ir_pass,
        #[DataCollectionOf(BlockFillFailure::class)]
        public DataCollection $preflight_failures,
        public array $expected_slugs,
    ) {}
}
