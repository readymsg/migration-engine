<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\GlobalStyleBrief;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use RuntimeException;

// Cache-backed BlockFillContextStore. Uses the application's default cache
// store (Redis in prod, array in tests via phpunit.xml CACHE_STORE=array).
// Brief is serialized as JSON via Data::toJson()/from() rather than PHP
// serialization so the wire format is inspectable and version-stable.
final class CacheBlockFillContextStore implements BlockFillContextStore
{
    // 24h TTL — generous so a conversion's jobs can finish even if Horizon
    // is throttled or paused. Reaped explicitly by forget(); the TTL is
    // a backstop against orphaned entries.
    private const TTL_SECONDS = 86_400;

    private const KEY_PREFIX = 'block-fill:context:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function put(string $conversionId, GlobalStyleBrief $brief): void
    {
        $this->cache->put($this->key($conversionId), $brief->toJson(), self::TTL_SECONDS);
    }

    public function get(string $conversionId): GlobalStyleBrief
    {
        /** @var mixed $raw */
        $raw = $this->cache->get($this->key($conversionId));
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException(
                "BlockFillContextStore: no GlobalStyleBrief for conversion '{$conversionId}' "
                .'— orchestrator must call put() before dispatching jobs.'
            );
        }

        return GlobalStyleBrief::from(json_decode($raw, associative: true));
    }

    public function forget(string $conversionId): void
    {
        $this->cache->forget($this->key($conversionId));
    }

    private function key(string $conversionId): string
    {
        return self::KEY_PREFIX.$conversionId;
    }
}
