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
    private const TTL_SECONDS = 600; // 10 min

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
     */
    public function registerOrGetExisting(string $token, string $url, string $freshConversionId): string
    {
        $key = $this->key($token, $url);
        /** @var mixed $existing */
        $existing = $this->cache->get($key);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $this->cache->put($key, $freshConversionId, self::TTL_SECONDS);

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
