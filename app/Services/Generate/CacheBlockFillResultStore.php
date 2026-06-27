<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\BlockFillFailure;
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
final class CacheBlockFillResultStore implements BlockFillResultStore
{
    private const TTL_SECONDS = 86_400;

    private const PAGE_PREFIX = 'block-fill:result:page:';

    private const FAILURE_PREFIX = 'block-fill:result:failure:';

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

    public function forget(string $conversionId): void
    {
        // Per-key forget would need an enumeration we don't have on a
        // generic Cache repo. The TTL is the backstop. Explicit cleanup
        // is wired through the orchestrator once it knows the slug set.
        // (No-op here is correct — leaving entries to expire after 24h
        // is preferable to a blind flush.)
    }

    private function pageKey(string $conversionId, string $pageSlug): string
    {
        return self::PAGE_PREFIX.$conversionId.':'.$pageSlug;
    }

    private function failureKey(string $conversionId, string $pageSlug): string
    {
        return self::FAILURE_PREFIX.$conversionId.':'.$pageSlug;
    }
}
