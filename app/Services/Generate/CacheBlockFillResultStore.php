<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\BlockFillFailure;
use App\Data\BlockFillReconcileState;
use App\Data\BlockFillResult;
use App\Data\FilledPage;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

// Cache-backed BlockFillResultStore. Uses the application's default cache
// store (Redis in prod, array in tests). JSON serialization so the wire
// format is inspectable.
//
// Per-page namespacing: success and failure entries live under DIFFERENT
// keys so a job can write a FilledPage and a later retry can write a
// BlockFillFailure (or vice versa) without one silently overwriting the
// other — reconciliation reads both namespaces and takes the most recent
// signal per slug. (In practice each job writes one or the other once.)
//
// Reconcile-state and reconciled-result namespaces are per-conversion,
// not per-page — they carry the state hand-off between dispatch() and
// reconcile() and the idempotency marker.
final class CacheBlockFillResultStore implements BlockFillResultStore
{
    private const TTL_SECONDS = 86_400;

    private const PAGE_PREFIX = 'block-fill:result:page:';

    private const FAILURE_PREFIX = 'block-fill:result:failure:';

    private const RECONCILE_STATE_PREFIX = 'block-fill:reconcile-state:';

    private const RECONCILED_RESULT_PREFIX = 'block-fill:reconciled:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function putFilledPage(string $conversionId, FilledPage $page): void
    {
        $this->cache->put(
            $this->pageKey($conversionId, $page->page_slug),
            $page->toJson(),
            self::TTL_SECONDS,
        );
    }

    public function putFailure(string $conversionId, BlockFillFailure $failure): void
    {
        $this->cache->put(
            $this->failureKey($conversionId, $failure->page_slug),
            $failure->toJson(),
            self::TTL_SECONDS,
        );
    }

    public function getFilledPage(string $conversionId, string $pageSlug): ?FilledPage
    {
        /** @var mixed $raw */
        $raw = $this->cache->get($this->pageKey($conversionId, $pageSlug));
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return FilledPage::from(json_decode($raw, associative: true));
    }

    public function getFailure(string $conversionId, string $pageSlug): ?BlockFillFailure
    {
        /** @var mixed $raw */
        $raw = $this->cache->get($this->failureKey($conversionId, $pageSlug));
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return BlockFillFailure::from(json_decode($raw, associative: true));
    }

    public function putReconcileState(string $conversionId, BlockFillReconcileState $state): void
    {
        $this->cache->put(
            $this->reconcileStateKey($conversionId),
            $state->toJson(),
            self::TTL_SECONDS,
        );
    }

    public function getReconcileState(string $conversionId): ?BlockFillReconcileState
    {
        /** @var mixed $raw */
        $raw = $this->cache->get($this->reconcileStateKey($conversionId));
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return BlockFillReconcileState::from(json_decode($raw, associative: true));
    }

    public function putReconciledResult(string $conversionId, BlockFillResult $result): void
    {
        $this->cache->put(
            $this->reconciledResultKey($conversionId),
            $result->toJson(),
            self::TTL_SECONDS,
        );
    }

    public function getReconciledResult(string $conversionId): ?BlockFillResult
    {
        /** @var mixed $raw */
        $raw = $this->cache->get($this->reconciledResultKey($conversionId));
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return BlockFillResult::from(json_decode($raw, associative: true));
    }

    public function forget(string $conversionId): void
    {
        // Per-key forget would need an enumeration we don't have on a
        // generic Cache repo. The TTL is the backstop. Explicit cleanup
        // is wired through the orchestrator once it knows the slug set.
        // (No-op here is correct — leaving entries to expire after 24h
        // is preferable to a blind flush.)
        //
        // We DO forget the per-conversion state + reconciled marker so a
        // re-run of the same conversion_id doesn't hit stale idempotency.
        $this->cache->forget($this->reconcileStateKey($conversionId));
        $this->cache->forget($this->reconciledResultKey($conversionId));
    }

    private function pageKey(string $conversionId, string $pageSlug): string
    {
        return self::PAGE_PREFIX.$conversionId.':'.$pageSlug;
    }

    private function failureKey(string $conversionId, string $pageSlug): string
    {
        return self::FAILURE_PREFIX.$conversionId.':'.$pageSlug;
    }

    private function reconcileStateKey(string $conversionId): string
    {
        return self::RECONCILE_STATE_PREFIX.$conversionId;
    }

    private function reconciledResultKey(string $conversionId): string
    {
        return self::RECONCILED_RESULT_PREFIX.$conversionId;
    }
}
