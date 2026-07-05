<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\BlockFillFailure;
use App\Data\BlockFillReconcileState;
use App\Data\BlockFillResult;
use App\Data\FilledPage;

// Per-conversion store spanning three namespaces:
//
//   (a) Per-page write surface: GeneratePageJob writes exactly one entry per
//       page — a FilledPage (success) or a BlockFillFailure (terminal
//       failure). Reconciliation reads both namespaces back and diffs
//       against the expected slug set.
//
//   (b) Reconcile state: written ONCE by BlockFill::dispatch() before the
//       Bus::batch is dispatched. Carries every input reconcile needs
//       (IrPassResult, preflight failures, expected slugs) so the reconcile
//       call can run in a DIFFERENT PROCESS (a worker) without re-doing
//       preflight. This is the load-bearing async contract.
//
//   (c) Final reconciled result: written by BlockFill::reconcile() when
//       the diff is done. Downstream stages (Assembler etc.) read from
//       here. Also serves as the idempotency marker — reconcile is a no-op
//       if the reconciled result is already present.
//
// Reconciliation is the AUTHORITY — a slug that's in neither (a) namespace
// means the job never wrote (silent loss); orchestrator surfaces that as
// an additional BlockFillFailure.
interface BlockFillResultStore
{
    // (a) Per-page write surface.
    public function putFilledPage(string $conversionId, FilledPage $page): void;

    public function putFailure(string $conversionId, BlockFillFailure $failure): void;

    public function getFilledPage(string $conversionId, string $pageSlug): ?FilledPage;

    public function getFailure(string $conversionId, string $pageSlug): ?BlockFillFailure;

    // (b) Reconcile state.
    public function putReconcileState(string $conversionId, BlockFillReconcileState $state): void;

    public function getReconcileState(string $conversionId): ?BlockFillReconcileState;

    // (c) Final reconciled result. Also the idempotency marker.
    public function putReconciledResult(string $conversionId, BlockFillResult $result): void;

    public function getReconciledResult(string $conversionId): ?BlockFillResult;

    public function forget(string $conversionId): void;
}
