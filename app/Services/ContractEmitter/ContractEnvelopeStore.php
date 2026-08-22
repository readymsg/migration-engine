<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Envelope;

// Per-conversion contract-envelope store. Written by
// FinalizeConversionJob after ContractPayloadEmitter runs; read by
// the contract preview endpoint when serving a live conversion.
//
// Same lifecycle as ConversionResultStore: 24-hour TTL, cache-backed
// (Redis in prod, array in tests). An envelope is persisted whether
// or not validation passed — an invalid envelope is still useful for
// debugging (the reviewer can inspect the block-delta summary and
// the specific validation errors that fired).
interface ContractEnvelopeStore
{
    public function put(string $conversionId, Envelope $envelope): void;

    public function get(string $conversionId): ?Envelope;

    public function forget(string $conversionId): void;
}
