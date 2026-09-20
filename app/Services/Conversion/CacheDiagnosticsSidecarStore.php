<?php

declare(strict_types=1);

namespace App\Services\Conversion;

use App\Data\DiagnosticsSidecar;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use JsonException;

final class CacheDiagnosticsSidecarStore implements DiagnosticsSidecarStore
{
    private const TTL_SECONDS = 86_400;

    private const KEY_PREFIX = 'conversion:diagnostics-sidecar:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function put(string $conversionId, DiagnosticsSidecar $sidecar): void
    {
        $this->cache->put(
            $this->key($conversionId),
            $sidecar->toJson(),
            self::TTL_SECONDS,
        );
    }

    public function get(string $conversionId): ?DiagnosticsSidecar
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

        return DiagnosticsSidecar::from($decoded);
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
