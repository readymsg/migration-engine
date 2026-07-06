<?php

declare(strict_types=1);

namespace App\Services\Conversion;

use App\Data\ConversionResult;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use JsonException;

final class CacheConversionResultStore implements ConversionResultStore
{
    private const TTL_SECONDS = 86_400;

    private const KEY_PREFIX = 'conversion:result:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function put(string $conversionId, ConversionResult $result): void
    {
        $this->cache->put(
            $this->key($conversionId),
            $result->toJson(),
            self::TTL_SECONDS,
        );
    }

    public function get(string $conversionId): ?ConversionResult
    {
        /** @var mixed $raw */
        $raw = $this->cache->get($this->key($conversionId));
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return ConversionResult::from($decoded);
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
