<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\BlockFillFailure;
use App\Data\FilledPage;

// Per-conversion, per-page result store. GeneratePageJobs write one entry
// per page (success → FilledPage, terminal failure → BlockFillFailure);
// the BlockFill orchestrator reads back the full set after the batch
// completes and reconciles against the expected slugs from IrPassResult.
//
// Two namespaces (FilledPage vs BlockFillFailure) keyed by
// (conversion_id, page_slug). Reconciliation is the AUTHORITY — a slug
// that's in neither namespace means the job never wrote (silent loss);
// orchestrator surfaces that as an additional BlockFillFailure.
interface BlockFillResultStore
{
    public function putFilledPage(string $conversionId, FilledPage $page): void;

    public function putFailure(string $conversionId, BlockFillFailure $failure): void;

    public function getFilledPage(string $conversionId, string $pageSlug): ?FilledPage;

    public function getFailure(string $conversionId, string $pageSlug): ?BlockFillFailure;

    public function forget(string $conversionId): void;
}
