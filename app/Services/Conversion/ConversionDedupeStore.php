<?php

declare(strict_types=1);

namespace App\Services\Conversion;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

// Trigger-endpoint dedupe layer. Same (token, url) posted twice within
// TTL_SECONDS returns the same conversion_id — a page refresh or
// double-click mid-demo picks up the in-progress conversion, does NOT
// start a second one.
//
// LOAD-BEARING for the demo: without this, a nervous demo watcher who
// hits refresh triggers a second $2-6 Sonnet conversion silently. The
// dedupe key is sha1(token + normalized_url); the cache maps that to
// the FIRST conversion_id emitted for that pair.
//
// 10-minute TTL means a genuine retry after 10 minutes (e.g. after a
// failed conversion) gets a fresh conversion_id — the dedupe only
// suppresses accidental doubles, not deliberate re-tries.
final class ConversionDedupeStore
{
    public const DEFAULT_TTL_SECONDS = 600; // 10 min for arbitrary URLs

    public const ALLOWLIST_TTL_SECONDS = 86_400; // 24h for allowlisted (known-safe) URLs

    private const KEY_PREFIX = 'conversion:dedupe:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Attempt to register a fresh conversion_id for (token, url). If a
     * prior conversion_id was registered within TTL, returns THAT one
     * (dedupe hit — POST responds 200 with the existing id). Otherwise
     * writes and returns the caller-provided id (dedupe miss — 202
     * with a fresh id).
     *
     * Callers pass a per-call TTL — the controller uses
     * ALLOWLIST_TTL_SECONDS (24h) for URLs on the demo allowlist
     * (safe: predictable cost sites can share their conversion for a
     * full day) and DEFAULT_TTL_SECONDS (10 min) otherwise.
     *
     * SHARED-TOKEN-DEDUPE PROPERTY: the key is sha1(token + url), so
     * TWO DIFFERENT VISITORS sharing the same public demo token who
     * POST the same URL will hit THE SAME dedupe entry. Visitor B
     * gets Visitor A's conversion_id — one conversion, one $3-6 bill,
     * everyone sees the same result. This is what bounds the hosted-
     * demo cost to (allowlist size × ~$3 × 1/day) instead of
     * (visitors × ~$3 × 1/day).
     */
    public function registerOrGetExisting(
        string $token,
        string $url,
        string $freshConversionId,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ): string {
        $key = $this->key($token, $url);
        /** @var mixed $existing */
        $existing = $this->cache->get($key);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $this->cache->put($key, $freshConversionId, $ttlSeconds);

        return $freshConversionId;
    }

    /**
     * Test hook — clear a dedupe entry (e.g. between test cases).
     */
    public function forget(string $token, string $url): void
    {
        $this->cache->forget($this->key($token, $url));
    }

    private function key(string $token, string $url): string
    {
        $normalizedUrl = strtolower(trim($url));

        return self::KEY_PREFIX.sha1($token.'|'.$normalizedUrl);
    }
}
