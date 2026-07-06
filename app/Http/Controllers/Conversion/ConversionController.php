<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conversion;

use App\Data\ConversionPipelineStage;
use App\Http\Controllers\Controller;
use App\Jobs\ConversionJob;
use App\Services\Conversion\ConversionCostGuard;
use App\Services\Conversion\ConversionDedupeStore;
use App\Services\Conversion\ConversionResultStore;
use App\Services\Conversion\ConversionStatusStore;
use App\Services\Generate\BlockFillResultStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Trigger + status + result endpoints for the step-6 conversion
// pipeline. Three routes, all under the EnsureDemoToken middleware
// (+ throttle on the POST).
//
//   POST /api/conversions
//   GET  /api/conversions/{id}/status
//   GET  /api/conversions/{id}
//
// DEDUPE ON REFRESH is load-bearing: same (token, url) POSTed again
// within 10 min returns the EXISTING conversion_id (200), not a new
// one (202). A nervous demo watcher hitting refresh must NOT trigger
// a second $2-6 Sonnet conversion. See ConversionDedupeStore.
final class ConversionController extends Controller
{
    public function __construct(
        private readonly ConversionDedupeStore $dedupeStore,
        private readonly ConversionStatusStore $statusStore,
        private readonly ConversionResultStore $resultStore,
        private readonly BlockFillResultStore $blockFillResultStore,
        private readonly ConversionCostGuard $costGuard,
    ) {}

    public function trigger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'url', 'max:2048'],
        ]);
        /** @var string $url */
        $url = $validated['url'];

        $token = (string) $request->header('X-Demo-Token', '');

        // Pre-dedupe cost guards: allowlist + daily budget. Fires
        // BEFORE dedupe so a rejected URL never touches the dedupe
        // cache (avoids polluting keys with junk URLs).
        $pre = $this->costGuard->checkPreDedupe($url);
        if (! $pre['allowed']) {
            return response()->json(['error' => $pre['error']], $pre['status']);
        }

        // Dedupe. TTL extended to 24h for allowlisted URLs (predictable
        // cost — safe to share the same conversion result for a full
        // day). Non-allowlisted URLs use the tighter 10-min default.
        $freshId = 'conv-'.Str::random(16);
        $dedupeTtl = $this->costGuard->isAllowlisted($url)
            ? ConversionDedupeStore::ALLOWLIST_TTL_SECONDS
            : ConversionDedupeStore::DEFAULT_TTL_SECONDS;
        $conversionId = $this->dedupeStore->registerOrGetExisting($token, $url, $freshId, $dedupeTtl);

        $isNew = $conversionId === $freshId;

        if ($isNew) {
            // Fresh dispatch — check concurrency LAST (dedupe hits
            // above skipped this because they don't consume
            // concurrency; a fresh dispatch does).
            $concurrency = $this->costGuard->checkConcurrency();
            if (! $concurrency['allowed']) {
                // Roll back the dedupe registration so the next call
                // doesn't return this un-dispatched conversion_id as a
                // "hit."
                $this->dedupeStore->forget($token, $url);

                return response()->json(['error' => $concurrency['error']], $concurrency['status']);
            }

            // Guards pass — commit spend + concurrency counters,
            // begin status, dispatch.
            $this->costGuard->commitDispatch();
            $this->statusStore->begin($conversionId, $url);
            ConversionJob::dispatch($conversionId, $url);
        }

        return response()->json([
            'conversion_id' => $conversionId,
            'status_url' => route('conversions.status', $conversionId),
            'result_url' => route('conversions.show', $conversionId),
            'preview_url' => url('/preview/'.$conversionId),
            'deduped' => ! $isNew,
        ], $isNew ? 202 : 200);
    }

    public function status(string $conversionId): JsonResponse
    {
        $snapshot = $this->statusStore->get($conversionId);
        if ($snapshot === null) {
            return response()->json(['error' => 'unknown conversion_id'], 404);
        }

        $body = $snapshot->toArray();
        $body['final_status'] = $snapshot->finalStatus();

        // Compute block-fill progress on the fly from the block-fill
        // stores. Cheap read + no worker-side status dependency (see
        // ConversionStatusStore docblock).
        if ($snapshot->stage === ConversionPipelineStage::BlockFill) {
            $progress = $this->computeBlockFillProgress($conversionId);
            if ($progress !== null) {
                $body['block_fill_progress'] = $progress;
            }
        }

        return response()->json($body);
    }

    public function show(string $conversionId): JsonResponse
    {
        $result = $this->resultStore->get($conversionId);
        if ($result === null) {
            // Distinguish "unknown" (no such conversion) from
            // "not-ready-yet" (conversion exists but hasn't finished
            // finalize). The status store answers that.
            $snapshot = $this->statusStore->get($conversionId);
            if ($snapshot === null) {
                return response()->json(['error' => 'unknown conversion_id'], 404);
            }

            return response()->json([
                'error' => 'not ready',
                'stage' => $snapshot->stage->value,
                'status_url' => route('conversions.status', $conversionId),
            ], 409);
        }

        return response()->json($result->toArray());
    }

    /**
     * @return ?array{done: int, total: int}
     */
    private function computeBlockFillProgress(string $conversionId): ?array
    {
        $state = $this->blockFillResultStore->getReconcileState($conversionId);
        if ($state === null) {
            return null;
        }

        $total = count($state->expected_slugs);
        $done = 0;
        foreach ($state->expected_slugs as $slug) {
            if ($this->blockFillResultStore->getFilledPage($conversionId, $slug) !== null
                || $this->blockFillResultStore->getFailure($conversionId, $slug) !== null) {
                $done++;
            }
        }

        return ['done' => $done, 'total' => $total];
    }
}
