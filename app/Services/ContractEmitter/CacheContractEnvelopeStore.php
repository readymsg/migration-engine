<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Envelope;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use JsonException;

final class CacheContractEnvelopeStore implements ContractEnvelopeStore
{
    private const TTL_SECONDS = 86_400;

    private const KEY_PREFIX = 'conversion:envelope:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function put(string $conversionId, Envelope $envelope): void
    {
        $this->cache->put(
            $this->key($conversionId),
            $envelope->toJson(),
            self::TTL_SECONDS,
        );
    }

    public function get(string $conversionId): ?Envelope
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

        return Envelope::from($decoded);
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
