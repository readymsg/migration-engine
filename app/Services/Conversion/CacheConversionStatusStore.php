<?php

declare(strict_types=1);

namespace App\Services\Conversion;

use App\Data\ConversionPipelineStage;
use App\Data\ConversionStatusSnapshot;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use JsonException;

// Cache-backed ConversionStatusStore. Same posture as the other
// per-conversion stores in the engine: 24h TTL, JSON serialization,
// cross-process safe via Redis in prod / array in tests.
//
// Failure semantics: fail() is FIRST-WIN. If a downstream stage
// throws after an earlier stage already wrote a failure, we keep the
// first (upstream root cause) not the second (downstream cascade
// effect). This matches the "trust structural signals" posture — the
// earliest failure is the one to surface.
//
// Terminal semantics: once stage is Complete/Partial/Failed, advance()
// is a no-op. Prevents sweeper re-drives from mutating a terminated
// conversion's status.
final class CacheConversionStatusStore implements ConversionStatusStore
{
    private const TTL_SECONDS = 86_400;

    private const KEY_PREFIX = 'conversion:status:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function begin(string $conversionId, string $url): void
    {
        $now = time();
        $snapshot = new ConversionStatusSnapshot(
            conversion_id: $conversionId,
            url: $url,
            started_at: $now,
            stage: ConversionPipelineStage::Queued,
            stage_label: ConversionPipelineStage::Queued->humanLabel(),
            stage_started_at: $now,
            elapsed_seconds: 0,
        );
        $this->put($conversionId, $snapshot);
    }

    public function advance(string $conversionId, ConversionPipelineStage $stage): void
    {
        $current = $this->get($conversionId);
        if ($current === null) {
            // Missing snapshot — unexpected but not fatal. Create one
            // fresh (defensive: means begin() was skipped somehow).
            $now = time();
            $current = new ConversionStatusSnapshot(
                conversion_id: $conversionId,
                url: '',
                started_at: $now,
                stage: $stage,
                stage_label: $stage->humanLabel(),
                stage_started_at: $now,
                elapsed_seconds: 0,
            );
            $this->put($conversionId, $current);

            return;
        }
        if ($current->stage->isTerminal()) {
            return; // terminal — protect against sweeper re-drives
        }

        $now = time();
        $this->put($conversionId, new ConversionStatusSnapshot(
            conversion_id: $current->conversion_id,
            url: $current->url,
            started_at: $current->started_at,
            stage: $stage,
            stage_label: $stage->humanLabel(),
            stage_started_at: $now,
            elapsed_seconds: $now - $current->started_at,
            block_fill_progress: $current->block_fill_progress,
            failure_reason: $current->failure_reason,
        ));
    }

    public function complete(string $conversionId, ConversionPipelineStage $terminalStage): void
    {
        if (! in_array($terminalStage, [ConversionPipelineStage::Complete, ConversionPipelineStage::Partial], true)) {
            // Called with a non-terminal stage; refuse. Callers must
            // use advance() or fail() for those.
            return;
        }
        $current = $this->get($conversionId);
        if ($current === null || $current->stage->isTerminal()) {
            return; // idempotent
        }
        $now = time();
        $this->put($conversionId, new ConversionStatusSnapshot(
            conversion_id: $current->conversion_id,
            url: $current->url,
            started_at: $current->started_at,
            stage: $terminalStage,
            stage_label: $terminalStage->humanLabel(),
            stage_started_at: $now,
            elapsed_seconds: $now - $current->started_at,
            block_fill_progress: $current->block_fill_progress,
            failure_reason: null,
        ));
    }

    public function fail(string $conversionId, string $failureReason): void
    {
        $current = $this->get($conversionId);
        if ($current === null) {
            // No prior begin — write a bare failed snapshot so /status
            // still returns SOMETHING actionable. The elapsed_seconds
            // will be 0 which is honest ("we don't know how long this
            // ran").
            $now = time();
            $this->put($conversionId, new ConversionStatusSnapshot(
                conversion_id: $conversionId,
                url: '',
                started_at: $now,
                stage: ConversionPipelineStage::Failed,
                stage_label: ConversionPipelineStage::Failed->humanLabel(),
                stage_started_at: $now,
                elapsed_seconds: 0,
                failure_reason: $failureReason,
            ));

            return;
        }
        if ($current->stage === ConversionPipelineStage::Failed) {
            return; // first-win: keep the root cause
        }
        // Complete/Partial → Failed is NOT allowed (once we've
        // successfully written a terminal, don't retroactively fail).
        if ($current->stage->isTerminal()) {
            return;
        }
        $now = time();
        $this->put($conversionId, new ConversionStatusSnapshot(
            conversion_id: $current->conversion_id,
            url: $current->url,
            started_at: $current->started_at,
            stage: ConversionPipelineStage::Failed,
            stage_label: ConversionPipelineStage::Failed->humanLabel(),
            stage_started_at: $now,
            elapsed_seconds: $now - $current->started_at,
            block_fill_progress: $current->block_fill_progress,
            failure_reason: $failureReason,
        ));
    }

    public function updateBlockFillProgress(string $conversionId, array $progress): void
    {
        $current = $this->get($conversionId);
        if ($current === null || $current->stage !== ConversionPipelineStage::BlockFill) {
            return; // only meaningful during BlockFill stage
        }
        $this->put($conversionId, new ConversionStatusSnapshot(
            conversion_id: $current->conversion_id,
            url: $current->url,
            started_at: $current->started_at,
            stage: $current->stage,
            stage_label: $current->stage_label,
            stage_started_at: $current->stage_started_at,
            elapsed_seconds: time() - $current->started_at,
            block_fill_progress: $progress,
            failure_reason: $current->failure_reason,
        ));
    }

    public function get(string $conversionId): ?ConversionStatusSnapshot
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

        return ConversionStatusSnapshot::from($decoded);
    }

    private function put(string $conversionId, ConversionStatusSnapshot $snapshot): void
    {
        $this->cache->put(
            $this->key($conversionId),
            $snapshot->toJson(),
            self::TTL_SECONDS,
        );
    }

    private function key(string $conversionId): string
    {
        return self::KEY_PREFIX.$conversionId;
    }
}
